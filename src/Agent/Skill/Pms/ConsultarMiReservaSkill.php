<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use App\Message\Service\MessageDataResolverRegistry;
use App\Security\Roles;

/**
 * Los datos de la reserva de quien está escribiendo.
 *
 * 🔑 Es el ejemplo del patrón acotado por contexto: **no recibe qué reserva consultar**. La
 * saca de `ActorInterface::contextoId()`, que es la conversación por la que llegó el mensaje.
 * Un huésped no puede pedir la reserva de otro porque no hay parámetro donde escribirlo — la
 * frontera está en el diseño de la skill, no en una comprobación que se pueda olvidar.
 *
 * Su hermana para el equipo es {@see BuscarReservaSkill}, y están separadas justo por eso.
 */
final readonly class ConsultarMiReservaSkill implements SkillInterface
{
    public function __construct(
        private MessageDataResolverRegistry $resolvers
    ) {}

    public function nombre(): string
    {
        return 'consultar_mi_reserva';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Devuelve los datos de la reserva de la persona con la que estás '
                . 'hablando: fechas de entrada y salida, casita, noches, huéspedes, '
                . 'localizador, total, pagado y saldo pendiente. Incluye dos enlaces que '
                . 'puedes darle directamente: guide_url, su guía personal con la llegada, el '
                . 'wifi y las instrucciones, y tours_catalog_url, el catálogo de tours. Úsala '
                . 'cuando pregunten por su propia reserva, su check-in, su casita, su guía, '
                . 'qué hacer en la zona o lo que deben. No necesita parámetros: siempre '
                . 'consulta la reserva de esta conversación.',
        );
    }

    /** El huésped la tiene por serlo; el equipo, por poder ver reservas. */
    public function rolesRequeridos(): array
    {
        return [Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        if ($actor->contextoTipo() === null || $actor->contextoId() === null) {
            return SkillResult::error('Esta conversación no está asociada a ninguna reserva.');
        }

        $resolver = $this->resolvers->getResolver($actor->contextoTipo());
        if ($resolver === null) {
            return SkillResult::error('No hay datos de reserva para este tipo de conversación.');
        }

        $variables = $resolver->getMessageVariables($actor->contextoId());

        // Sólo escalares: las variables del resolver alimentan plantillas, y colar aquí
        // estructuras internas sería filtrar más de lo que se pidió.
        $datos = array_filter(
            $variables,
            static fn ($valor) => is_scalar($valor) && (string) $valor !== ''
        );

        // 🔗 El id es lo que permite ENCADENAR: sin él, el modelo no puede pasar esta
        // reserva a la siguiente skill y cada consulta muere en sí misma. `getMessageVariables()`
        // no lo trae porque nació para rellenar plantillas, donde un UUID no pinta nada.
        $datos['reserva_id'] = $actor->contextoId();

        return SkillResult::ok($datos);
    }
}
