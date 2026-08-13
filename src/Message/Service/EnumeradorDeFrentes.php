<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Contract\Frente;
use App\Message\Contract\FrentesPorDominioInterface;
use App\Message\Contract\MomentoDeFrente;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Los asuntos abiertos de quien escribe, de TODOS los negocios, más las puertas de venta.
 *
 * Es la pieza transversal del modelo de frentes: recorre las implementaciones registradas por
 * tag ({@see FrentesPorDominioInterface}), junta lo vivo de cada negocio y añade al final la
 * venta sintética de cada negocio vendible.
 *
 * ── Por qué la venta va SIEMPRE, para todos ─────────────────────────────────
 * Podría añadirse sólo cuando el mensaje huele a compra, y sería peor: obligaría a decidir
 * «¿esto es una compra?» ANTES de clasificar el mensaje, que es justo lo que el triaje hace
 * mejor. Poniéndola siempre, dos casos que parecían excepciones se resuelven con la regla
 * normal —un cliente de tours preguntando por casitas, y un huésped que quiere ampliar— y el
 * prospecto sin nada deja de ser un caso aparte: simplemente ve sólo las puertas.
 *
 * Cuesta una línea por negocio en el bloque volátil del prompt. Es barato y es la diferencia
 * entre un agente que sabe vender y uno que sólo atiende.
 */
final readonly class EnumeradorDeFrentes
{
    /**
     * Tope de asuntos con entidad que se le enseñan al modelo.
     *
     * No es una optimización prematura: el bloque de frentes viaja en la parte VOLÁTIL del
     * prompt, se paga entera en cada turno y no se cachea. Un cliente veterano con quince
     * expedientes convertiría cada mensaje —incluido un «gracias»— en una factura de contexto.
     * Con más de este tope, se enseñan los más recientes; el resto se resume en una línea y, si
     * de verdad hablaba de uno de los que no salen, el camino largo tiene skills para buscarlo.
     */
    private const MAX_CON_ENTIDAD = 5;

    /**
     * @param iterable<FrentesPorDominioInterface> $dominios
     */
    public function __construct(
        #[AutowireIterator('app.message.frentes_dominio')]
        private iterable $dominios
    ) {}

    /**
     * Todo lo elegible por este teléfono: asuntos vivos primero, puertas de venta al final.
     *
     * El `porDefecto` se marca aquí y no en cada dominio porque sólo puede haber uno global:
     * es el primer asunto con entidad que devuelva el primer dominio que tenga algo. Si no hay
     * ninguno —un desconocido—, no se marca nada y el triaje elige por el mensaje, que es lo
     * correcto: con dos puertas de venta y cero historia, no hay ningún defecto razonable.
     *
     * @return list<Frente>
     */
    public function paraTelefono(?string $telefono): array
    {
        $conEntidad = [];
        $ventas = [];

        foreach ($this->dominios as $dominio) {
            foreach ($dominio->frentesVivos($telefono) as $frente) {
                $conEntidad[] = $frente;
            }

            if ($dominio->esVendible()) {
                $ventas[] = new Frente(
                    negocio: $dominio->negocio(),
                    momento: MomentoDeFrente::Venta,
                    etiqueta: $dominio->etiquetaDeVenta(),
                );
            }
        }

        $conEntidad = array_slice($conEntidad, 0, self::MAX_CON_ENTIDAD);

        if ($conEntidad !== []) {
            $primero = $conEntidad[0];
            $conEntidad[0] = new Frente(
                negocio: $primero->negocio,
                momento: $primero->momento,
                etiqueta: $primero->etiqueta,
                entidadTipo: $primero->entidadTipo,
                entidadId: $primero->entidadId,
                porDefecto: true,
            );
        }

        return array_values([...$conEntidad, ...$ventas]);
    }

    /**
     * El bloque que se le enseña al modelo. **Va en el contexto VOLÁTIL, nunca en las reglas
     * cacheadas**: depende de quién escribe, y meterlo en el prefijo con marca de caché
     * significaría una caché por cliente, que es peor que no cachear.
     *
     * @param list<Frente> $frentes
     */
    public function bloqueParaElPrompt(array $frentes): string
    {
        if ($frentes === []) {
            return '';
        }

        return "FRENTES (asuntos abiertos de quien escribe):\n"
            . implode("\n", array_map(static fn (Frente $f): string => $f->comoLinea(), $frentes));
    }

    /**
     * Resuelve lo que eligió el modelo contra la lista real de este turno.
     *
     * Lista blanca estricta: un id que no esté aquí se descarta. Es el mismo blindaje que ya
     * se aplica a la skill que el triaje propone, y por el mismo motivo — un modelo puede
     * inventarse un identificador con toda la seguridad del mundo.
     *
     * @param list<Frente> $frentes
     */
    public function porId(array $frentes, ?string $id): ?Frente
    {
        $id = trim((string) $id);

        if ($id === '') {
            return null;
        }

        foreach ($frentes as $frente) {
            if ($frente->id() === $id) {
                return $frente;
            }
        }

        return null;
    }

    /**
     * El frente que se toma cuando no hay elección válida: el marcado por defecto, o ninguno.
     *
     * Devolver `null` es un resultado legítimo y no una degradación: sin asuntos abiertos no
     * hay nada que asumir, y el comportamiento de hoy —resolver el dominio por el
     * `context_type` de la conversación— sigue siendo el fallo seguro.
     *
     * @param list<Frente> $frentes
     */
    public function porDefecto(array $frentes): ?Frente
    {
        foreach ($frentes as $frente) {
            if ($frente->porDefecto) {
                return $frente;
            }
        }

        return null;
    }

    /**
     * Los negocios que existen, para la unión de {@see \App\Agent\Access\ActorInterface::dominios()}.
     *
     * @return list<string>
     */
    public function negocios(): array
    {
        $negocios = [];

        foreach ($this->dominios as $dominio) {
            $negocios[] = $dominio->negocio();
        }

        return array_values(array_unique($negocios));
    }

    /**
     * Los negocios que se le pueden vender a cualquiera.
     *
     * Es lo que impide que el filtro de dominio deje sin skills de venta a quien todavía no ha
     * comprado nada: sin esto, un prospecto —que por definición no tiene asuntos vivos— se
     * quedaría con el catálogo vacío justo cuando más falta hace atenderlo.
     *
     * @return list<string>
     */
    public function negociosVendibles(): array
    {
        $negocios = [];

        foreach ($this->dominios as $dominio) {
            if ($dominio->esVendible()) {
                $negocios[] = $dominio->negocio();
            }
        }

        return array_values(array_unique($negocios));
    }
}
