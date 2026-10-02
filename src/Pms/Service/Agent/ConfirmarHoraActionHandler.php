<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Access\AgentActorFactory;
use App\Agent\Action\BotActionHandlerInterface;
use App\Agent\Action\ParametrosDeAccion;
use App\Agent\Skill\Pms\ConfirmarHoraSkill;
use App\Message\Entity\Message;
use App\Message\Service\Agent\SendTemplateActionHandler;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Pms\Service\Reserva\HoraDeLaEstancia;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * La acción «el huésped confirma la hora de siempre» de un botón: [Salgo a las 10:00].
 *
 * Sin pasar por el modelo: el botón ya dice qué hora es, así que se apunta con la misma skill que
 * usa el agente (`confirmar_hora`, que la deja confirmada y avisa al equipo) y se contesta con una
 * plantilla fija. Es justo el caso que antes no se distinguía de no haber contestado: una salida
 * a las 10:00 es la de todos.
 *
 * Parámetros de la regla:
 * - `extremo`: `salida` o `llegada`.
 * - `plantilla_respuesta`: código de la plantilla con que se le contesta (opcional).
 *
 * La hora es la del alojamiento (`HoraDeLaEstancia::limite()`), no un número escrito en la regla:
 * si un día cambia el check-out, el botón sigue diciendo la verdad.
 */
final readonly class ConfirmarHoraActionHandler implements BotActionHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private AgentActorFactory $actores,
        private ConfirmarHoraSkill $confirmarHora,
        private PmsGuiaEstanciaResolver $estancias,
        private HoraDeLaEstancia $horas,
        private SendTemplateActionHandler $plantillas,
        private LoggerInterface $logger,
    ) {}

    public function getActionKey(): string
    {
        return 'confirmar_hora';
    }

    public function getActionLabel(): string
    {
        return 'Confirmar la hora de siempre (check-in / check-out) y contestar';
    }

    public function execute(string $mensajeEntranteId, ParametrosDeAccion $parametros): void
    {
        $mensaje = $this->em->getRepository(Message::class)->find($mensajeEntranteId);
        $conversacion = $mensaje instanceof Message ? $mensaje->getConversation() : null;

        if ($conversacion === null || $conversacion->getContextType() !== 'pms_reserva') {
            $this->logger->warning('Bot: confirmar_hora sin reserva detrás.', ['mensaje' => $mensajeEntranteId]);

            return;
        }

        $esSalida = $parametros->texto('extremo') !== 'llegada';
        $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());
        $evento = $reserva instanceof PmsReserva
            ? $this->estancias->resolver($reserva->getEventosActivosGuia())['evento']
            : null;

        if (!$evento instanceof PmsEventoCalendario) {
            // Varias casitas sin decir cuál, o ninguna activa: que lo pregunte el agente.
            $this->logger->warning('Bot: confirmar_hora no sabe a qué estancia apuntarlo.', ['mensaje' => $mensajeEntranteId]);

            return;
        }

        $actor = $this->actores->huesped('boton', 'pms_reserva', $conversacion->getContextId(), (string) $conversacion->getId());
        $resultado = $this->confirmarHora->ejecutar([
            'extremo' => $esSalida ? 'salida' : 'llegada',
            'hora' => $this->horas->limite($evento, $esSalida),
        ], $actor);

        if ($resultado->esError()) {
            $this->logger->warning('Bot: confirmar_hora no pudo apuntarla.', ['mensaje' => $mensajeEntranteId, 'error' => $resultado->error]);

            return;
        }

        if ($parametros->texto('plantilla_respuesta') !== null) {
            $this->plantillas->execute($mensajeEntranteId, ParametrosDeAccion::desdeCrudo([
                'template_code' => $parametros->texto('plantilla_respuesta'),
            ]));
        }
    }
}
