<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Message\Service\MessageDataResolverRegistry;
use App\Security\Roles;

/**
 * Los datos de la reserva del huésped que está escribiendo.
 *
 * 🔑 Es el ejemplo del patrón acotado por contexto: **no recibe qué reserva consultar**. La
 * saca de `AgentActor::$contextoId`, que es la conversación por la que llegó el mensaje. Un
 * huésped no puede pedir la reserva de otro porque no hay parámetro donde escribirlo — la
 * frontera está en el diseño de la herramienta, no en una comprobación que se pueda olvidar.
 *
 * Al equipo también le sirve: cuando un operador abre el chat de un huésped, el contexto es
 * esa misma conversación.
 */
final readonly class ConsultarMiReservaTool implements AgentToolInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolvers
    ) {}

    public function nombre(): string
    {
        return 'consultar_mi_reserva';
    }

    public function definicion(): array
    {
        return [
            'description' => 'Devuelve los datos de la reserva de la persona con la que estás '
                . 'hablando: fechas, casita, número de huéspedes, enlaces y estado de pago. '
                . 'Úsala cuando pregunten por su propia reserva, su check-in, su casita o su '
                . 'estancia. No necesita parámetros: siempre consulta la reserva de esta '
                . 'conversación.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
            ],
        ];
    }

    /** El huésped la tiene por ser huésped; el equipo, por poder ver reservas. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, AgentActor $actor): string
    {
        if ($actor->contextoTipo === null || $actor->contextoId === null) {
            return json_encode(
                ['error' => 'Esta conversación no está asociada a ninguna reserva.'],
                JSON_UNESCAPED_UNICODE
            );
        }

        $resolver = $this->resolvers->getResolver($actor->contextoTipo);
        if ($resolver === null) {
            return json_encode(
                ['error' => 'No hay datos de reserva para este tipo de conversación.'],
                JSON_UNESCAPED_UNICODE
            );
        }

        $variables = $resolver->getMessageVariables($actor->contextoId);

        // Sólo escalares: las variables del resolver alimentan plantillas, y colar aquí
        // estructuras internas sería filtrarle al huésped más de lo que pidió.
        $datos = array_filter(
            $variables,
            static fn ($valor) => is_scalar($valor) && (string) $valor !== ''
        );

        return json_encode($datos, JSON_UNESCAPED_UNICODE);
    }
}
