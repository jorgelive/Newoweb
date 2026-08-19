<?php

declare(strict_types=1);

namespace App\Agent\Skill\Travel;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambia una tarifa que ya existe.
 *
 * Separada de {@see CrearTarifaSkill}; las reglas comunes viven en {@see TarifaDesdeEntrada}.
 *
 * ⚠️ **La previsualización enseña el ANTES y el DESPUÉS.** En una creación basta con ver cómo
 * queda; aquí lo que decide al operador es qué cambia — y sobre todo qué NO cambia, porque lo
 * que no se menciona se conserva y eso es fácil de dar por perdido.
 */
final readonly class ModificarTarifaSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TarifaDesdeEntrada $tarifas,
    ) {}

    public function nombre(): string
    {
        return 'modificar_tarifa';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'CAMBIA una tarifa que YA EXISTE en el catálogo de servicios y tours. '
                . 'Úsala para «súbele a 45 dólares la tarifa de niños del city tour», «ponle tope '
                . 'de 11 años», «cámbiale la moneda a soles». Para añadir una tarifa que no '
                . 'existe es crear_tarifa. Necesita el tarifa_id, que sale de buscar_tarifas: NO '
                . 'te lo inventes. MODIFICA DATOS: llama SIEMPRE primero con confirmado=false y '
                . 'enséñale al operador el ANTES y el DESPUÉS antes de pedirle el sí. Las '
                . 'condiciones que no menciones SE QUEDAN COMO ESTÁN —por eso «súbele el precio» '
                . 'no borra las edades—; para QUITAR un límite mándalo explícitamente en 0.',
            parametros: array_merge(
                [
                    SkillParameter::texto('tarifa_id', 'Id de la tarifa, tal y como lo devolvió '
                        . 'buscar_tarifas.'),
                ],
                TarifaDesdeEntrada::parametrosComunes()
            ),
        );
    }

    public function dominios(): array
    {
        return [];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::OPERACIONES_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    /** @param array<string, mixed> $entrada */
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $tarifa = $this->tarifas->buscarTarifa(trim((string) ($entrada['tarifa_id'] ?? '')));

        if ($tarifa === null) {
            return SkillResult::error(
                'No encuentro esa tarifa. Búscala antes con buscar_tarifas y usa el tarifa_id '
                . 'que te devuelva; no lo escribas de memoria.'
            );
        }

        $antes = $this->tarifas->retrato($tarifa);
        $error = $this->tarifas->aplicar($tarifa, $entrada, exigeNombre: false);

        if ($error !== null) {
            return SkillResult::error($error);
        }

        $despues = $this->tarifas->retrato($tarifa);

        if (!(bool) ($entrada['confirmado'] ?? false)) {
            // ⚠️ La entidad está GESTIONADA y ya lleva los cambios en memoria: sin esto, un
            // flush posterior de cualquier otra cosa en el mismo turno los arrastraría a la
            // base sin que nadie los aprobara. Se descartan.
            $this->em->refresh($tarifa);

            return SkillResult::ok([
                'accion' => 'modificar',
                'antes' => $antes,
                'despues' => $despues,
                'confirmado' => false,
                'instruccion' => 'ENSÉÑALE LOS DOS y di explícitamente qué cambia y qué se '
                    . 'conserva. Espera su sí antes de volver a llamarme con confirmado=true.',
            ]);
        }

        $this->em->flush();

        return SkillResult::ok([
            'accion' => 'modificada',
            'antes' => $antes,
            'tarifa' => $despues,
            'confirmado' => true,
        ]);
    }
}
