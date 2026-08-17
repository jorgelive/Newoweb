<?php

declare(strict_types=1);

namespace App\Agent\Provider\Google;

use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Access\ActorInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traduce las skills del dominio al *function calling* de Gemini.
 *
 * 🔑 **Es el único sitio del proyecto que sabe cómo Google espera una herramienta**, igual que
 * {@see \App\Agent\Provider\Anthropic\AnthropicSkillAdapter} lo es para Anthropic. Las skills
 * no cambian al añadir un proveedor; se añade un adaptador.
 *
 * Diferencia estructural con Anthropic, y es la que obliga a que este archivo exista: el SDK
 * de Anthropic trae un *tool runner* que ejecuta los callbacks solo. Aquí no hay SDK, así que
 * el bucle lo lleva {@see GoogleAIEngine} y este adaptador expone dos mitades sueltas —
 * `declaraciones()` para el envío y `ejecutar()` para la vuelta.
 */
final readonly class GoogleAISkillAdapter
{
    public function __construct(
        private LoggerInterface $logger,
        private GuardiaDeSkills $guardia
    ) {}

    /**
     * @param list<SkillInterface> $skills
     * @return list<array<string, mixed>> `functionDeclarations` tal y como las pide la API.
     */
    public function declaraciones(array $skills): array
    {
        $declaraciones = [];

        foreach ($skills as $skill) {
            $declaracion = [
                'name' => $skill->nombre(),
                'description' => $skill->definicion()->descripcion,
            ];

            // ⚠️ Gotcha: Gemini devuelve 400 si una declaración trae `parameters` de tipo
            // OBJECT sin propiedades. Anthropic acepta el `{}` vacío; aquí la clave se omite
            // entera. Skills sin argumentos (consultar_ocupacion, listar_salidas…) rompían
            // TODO el turno, no sólo su propia llamada.
            $esquema = $this->esquema($skill);
            if ($esquema !== null) {
                $declaracion['parameters'] = $esquema;
            }

            $declaraciones[] = $declaracion;
        }

        return $declaraciones;
    }

    /**
     * Ejecuta la skill que pidió el modelo y devuelve lo que hay que mandarle de vuelta.
     *
     * @param list<SkillInterface> $skills Las permitidas para este actor. Una llamada a algo
     *        que no esté aquí se contesta con un error, no se ejecuta: la lista ES el permiso.
     * @param array<string, mixed> $entrada
     * @param list<string> $usadas Se rellena por referencia con los nombres invocados.
     * @return array<string, mixed> Cuerpo de `functionResponse.response`.
     */
    public function ejecutar(
        array $skills,
        string $nombre,
        array $entrada,
        ActorInterface $actor,
        array &$usadas,
    ): array {
        $skill = null;
        foreach ($skills as $candidata) {
            if ($candidata->nombre() === $nombre) {
                $skill = $candidata;
                break;
            }
        }

        if ($skill === null) {
            $this->logger->warning(sprintf(
                'Agent (google): el modelo pidió la skill desconocida "%s" para %s.',
                $nombre,
                $actor->etiqueta()
            ));

            return ['error' => sprintf('No existe la herramienta "%s".', $nombre)];
        }

        $usadas[] = $skill->nombre();

        $this->logger->info(sprintf(
            'Agent: %s usa la skill "%s".',
            $actor->etiqueta(),
            $skill->nombre()
        ));

        // El cierre, antes de tocar nada. El catálogo dice qué se ofrece; esto dice qué se
        // ejecuta. Ver GuardiaDeSkills.
        $bloqueo = $this->guardia->motivoDeBloqueo($skill, $actor);

        if ($bloqueo !== null) {
            $this->logger->warning(sprintf(
                'Agent: BLOQUEADA la skill "%s" para %s: %s',
                $skill->nombre(),
                $actor->etiqueta(),
                $bloqueo
            ));

            return ['error' => $bloqueo];
        }

        try {
            $resultado = $skill->ejecutar($entrada, $actor);
        } catch (Throwable $e) {
            // Un fallo de infraestructura no debe romper el turno: se le cuenta al modelo en su
            // idioma y el detalle queda en el log.
            $this->logger->error(sprintf(
                'Agent: la skill "%s" falló para %s: %s',
                $skill->nombre(),
                $actor->etiqueta(),
                $e->getMessage()
            ));

            return ['error' => 'La consulta no se pudo completar.'];
        }

        if ($resultado->esError()) {
            return ['error' => $resultado->error];
        }

        // No se usa `SkillResult::aJson()` —que es lo que hace el adaptador de Anthropic—
        // porque `functionResponse.response` tiene que ser un OBJETO JSON. Una skill que
        // devuelva una lista (`[...]`) o un array vacío (`[]` al serializar) daría un 400, así
        // que se envuelve bajo una clave.
        return $resultado->datos === [] || array_is_list($resultado->datos)
            ? ['resultado' => $resultado->datos]
            : $resultado->datos;
    }

    /**
     * @return array<string, mixed>|null `null` cuando la skill no tiene parámetros.
     */
    private function esquema(SkillInterface $skill): ?array
    {
        $definicion = $skill->definicion();

        if ($definicion->parametros === []) {
            return null;
        }

        $propiedades = [];
        foreach ($definicion->parametros as $parametro) {
            $propiedades[$parametro->nombre] = [
                // El esquema de Gemini es OpenAPI, no JSON Schema: el tipo es un enum en
                // mayúsculas (STRING, INTEGER, BOOLEAN). En minúsculas cuela a veces y falla
                // otras según la versión de la API; en mayúsculas es siempre válido.
                'type' => strtoupper($parametro->tipo),
                'description' => $parametro->descripcion,
            ];
        }

        $esquema = [
            'type' => 'OBJECT',
            'properties' => $propiedades,
        ];

        $requeridos = array_map(
            static fn (SkillParameter $p) => $p->nombre,
            $definicion->requeridos()
        );

        if ($requeridos !== []) {
            $esquema['required'] = $requeridos;
        }

        return $esquema;
    }
}
