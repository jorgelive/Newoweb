<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Security\Roles;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Qué casitas están libres entre dos fechas.
 *
 * Es una fachada delgada sobre `PmsDisponibilidadService`: la lógica de solape y las reglas
 * de qué ocupa una noche viven allí (docs/PmsDisponibilidad.md), no aquí.
 */
final readonly class ConsultarDisponibilidadTool implements AgentToolInterface
{
    public function __construct(
        private PmsDisponibilidadService $disponibilidad
    ) {}

    public function nombre(): string
    {
        return 'consultar_disponibilidad';
    }

    public function definicion(): array
    {
        return [
            'description' => 'Consulta qué casitas están libres en un rango de fechas. '
                . 'Úsala siempre que pregunten por disponibilidad, casitas libres, huecos, '
                . 'o si se puede alojar a alguien en unas fechas. Nunca respondas de memoria '
                . 'sobre disponibilidad: llama siempre a esta herramienta.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'desde' => [
                        'type' => 'string',
                        'description' => 'Fecha de entrada en formato YYYY-MM-DD.',
                    ],
                    'hasta' => [
                        'type' => 'string',
                        'description' => 'Fecha de salida en formato YYYY-MM-DD. '
                            . 'Es el día en que la casita queda libre: del 12 al 15 son 3 noches.',
                    ],
                    'pax' => [
                        'type' => 'integer',
                        'description' => 'Número de personas, si lo indican.',
                    ],
                ],
                'required' => ['desde', 'hasta'],
            ],
        ];
    }

    /**
     * Sólo el equipo. Un huésped que pregunta por disponibilidad general es una venta, y eso
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

    public function ejecutar(array $entrada, AgentActor $actor): string
    {
        try {
            $libres = $this->disponibilidad->buscar(
                new DateTimeImmutable((string) ($entrada['desde'] ?? '')),
                new DateTimeImmutable((string) ($entrada['hasta'] ?? '')),
                isset($entrada['pax']) ? (int) $entrada['pax'] : null,
            );
        } catch (InvalidArgumentException | Exception $e) {
            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'total' => count($libres),
            'casitas' => array_map(static fn ($u) => $u->toArray(), $libres),
        ], JSON_UNESCAPED_UNICODE);
    }
}
