<?php

declare(strict_types=1);

namespace App\Pms\Service\Agent;

use App\Agent\Action\BotActionHandlerInterface;
use App\Agent\Action\ParametrosDeAccion;
use App\Message\Entity\Message;
use App\Message\Service\Agent\SendTemplateActionHandler;
use App\Pms\Dispatch\AvisarHoraSinRespuestaDispatch;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\EstanciasDelBorde;
use App\Pms\Service\Reserva\PeticionDeHora;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * La acción de los botones que piden una hora que el huésped todavía no ha dicho:
 * «Necesito salir tarde», «Saldré antes», «Necesito llegar antes», «Llegaré más tarde».
 *
 * ── Por qué no va al agente ─────────────────────────────────────────────────
 * Iba (`pasar_al_agente`), y con Franco (W2YRVK, 02/10/2026) el modelo escaló al equipo ANTES de
 * saber la hora y volvió a avisar cuando la supo: dos avisos por la misma petición. Sin hora no
 * hay nada que decidir, así que esto sólo pregunta —con un mensaje fijo— y deja apuntado en la
 * estancia que hay una petición sin hora. Cuando la dice, la registra el agente con
 * `confirmar_hora`, que avisa UNA vez (dentro de horario, o fuera con lo que hace falta decidir).
 *
 * ── Y si no la dice ─────────────────────────────────────────────────────────
 * Con `avisar_sin_respuesta` —los que necesitan una decisión del equipo: salir tarde, llegar
 * antes— se encola un aviso a 2 horas, que se descarta si entretanto el huésped escribió o alguien
 * del equipo le contestó (`AvisarHoraSinRespuestaDispatchHandler`). Jorge: «¿qué pasa si sólo pone
 * saldré antes y después no responde nada?».
 *
 * Parámetros: `extremo` (salida|llegada), `pedido` (texto de la petición en la estancia, empieza
 * por «Pide salir» / «Pide entrar» para que `PeticionDeHora` la reconozca), `plantilla_respuesta`
 * y `avisar_sin_respuesta`.
 */
final readonly class PedirHoraActionHandler implements BotActionHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PeticionDeHora $peticiones,
        private SendTemplateActionHandler $plantillas,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    public function getActionKey(): string
    {
        return 'pedir_hora';
    }

    public function getActionLabel(): string
    {
        return 'Preguntar la hora (salir tarde / antes, llegar antes / más tarde) y dejar la petición';
    }

    public function execute(string $mensajeEntranteId, ParametrosDeAccion $parametros): void
    {
        $mensaje = $this->em->getRepository(Message::class)->find($mensajeEntranteId);
        $conversacion = $mensaje instanceof Message ? $mensaje->getConversation() : null;

        if ($conversacion === null || $conversacion->getContextType() !== 'pms_reserva') {
            $this->logger->warning('Bot: pedir_hora sin reserva detrás.', ['mensaje' => $mensajeEntranteId]);

            return;
        }

        $esSalida = $parametros->texto('extremo') !== 'llegada';
        $reserva = $this->em->getRepository(PmsReserva::class)->find($conversacion->getContextId());
        $estancias = $reserva instanceof PmsReserva ? EstanciasDelBorde::de($reserva->getEventosActivosGuia(), $esSalida) : [];
        $pedido = $parametros->texto('pedido') ?? ($esSalida ? 'Pide salir a otra hora, sin hora todavía' : 'Pide entrar a otra hora, sin hora todavía');

        foreach ($estancias as $evento) {
            $this->peticiones->dejar($evento, $pedido, $esSalida, (string) $conversacion->getId());
        }
        $this->em->flush();

        if ($parametros->texto('plantilla_respuesta') !== null) {
            $this->plantillas->execute($mensajeEntranteId, ParametrosDeAccion::desdeCrudo([
                'template_code' => $parametros->texto('plantilla_respuesta'),
            ]));
        }

        if ($estancias !== [] && $parametros->texto('avisar_sin_respuesta') === 'si') {
            $this->bus->dispatch(
                new AvisarHoraSinRespuestaDispatch($mensajeEntranteId, $esSalida),
                [new DelayStamp(AvisarHoraSinRespuestaDispatch::ESPERA_SEGUNDOS * 1000)]
            );
        }
    }
}
