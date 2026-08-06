<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use DateTimeImmutable;
use Throwable;

/**
 * Qué casitas están libres entre dos fechas.
 *
 * Fachada delgada sobre `PmsDisponibilidadService`: la lógica de solape y qué estados ocupan
 * una noche viven allí (docs/PmsDisponibilidad.md), no aquí.
 */
final readonly class ConsultarDisponibilidadSkill implements SkillInterface
{
    public function __construct(
        private PmsDisponibilidadService $disponibilidad
    ) {}

    public function nombre(): string
    {
        return 'consultar_disponibilidad';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Consulta qué casitas están libres en un rango de fechas. '
                . 'Úsala siempre que pregunten por disponibilidad, casitas libres, huecos, '
                . 'o si se puede alojar a alguien en unas fechas. Nunca respondas de memoria '
                . 'sobre disponibilidad: llama siempre a esta skill.',
            parametros: [
                SkillParameter::texto('desde', 'Fecha de entrada en formato YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Fecha de salida en formato YYYY-MM-DD. '
                    . 'Es el día en que la casita queda libre: del 12 al 15 son 3 noches.'),
                SkillParameter::entero('pax', 'Número de personas, si lo indican.'),
            ],
        );
    }

    /**
     * Sólo el equipo. Un huésped preguntando por disponibilidad general es una venta, y eso
     * pasa por una persona: implicaría precio, y `tarifa_base` NO es el precio de venta.
     */
    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        try {
            $libres = $this->disponibilidad->buscar(
                new DateTimeImmutable((string) ($entrada['desde'] ?? '')),
                new DateTimeImmutable((string) ($entrada['hasta'] ?? '')),
                isset($entrada['pax']) ? (int) $entrada['pax'] : null,
            );
        } catch (Throwable $e) {
            return SkillResult::error($e->getMessage());
        }

        return SkillResult::ok([
            'total' => count($libres),
            'casitas' => array_map(static fn ($u) => $u->toArray(), $libres),
        ]);
    }
}
