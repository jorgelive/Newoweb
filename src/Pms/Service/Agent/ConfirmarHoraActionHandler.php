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
use App\Pms\Service\Reserva\EstanciasDelBorde;
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
        $estancias = $reserva instanceof PmsReserva ? EstanciasDelBorde::de($reserva->getEventosActivosGuia(), $esSalida) : [];

        if ($estancias === []) {
            $this->logger->warning('Bot: confirmar_hora no encuentra ninguna estancia activa.', ['mensaje' => $mensajeEntranteId]);

            return;
        }

        // 🔥 **Todas las casitas que entran o salen ese día, con UN aviso.** Con varias, el botón
        // se quedaba en un `warning` y el huésped sin respuesta: un botón no puede preguntar «¿de
        // cuál?», y «salgo a las 10:00» de una familia en dos casitas vale para las dos (Lizbeth,
        // KXET9H, 02/10/2026). Después se llamó una vez por casita y al equipo le llegaba un aviso
        // por cada una; ahora va todo junto por `ConfirmarHoraSkill::confirmarEn()` (Jorge: «si
        // salen el mismo día, júntalo»).
        //
        // La hora: `hora: estancia` es la que figura en la estancia —la del alojamiento si nadie
        // la cambió, la acordada si sí—, que es lo que dice el botón «Salgo a esa hora». Sin el
        // parámetro, la del alojamiento: el botón viejo dice «Salgo a las 10:00» en el texto. Si
        // dos casitas tuvieran horas distintas, va un aviso por hora: no se le apunta a nadie una
        // hora que no es la suya.
        $actor = $this->actores->huesped('boton', 'pms_reserva', $conversacion->getContextId(), (string) $conversacion->getId());
        $porHora = [];

        foreach ($estancias as $evento) {
            $momento = $esSalida ? $evento->getFin() : $evento->getInicio();
            $hora = $parametros->texto('hora') === 'estancia' && $momento !== null
                ? $momento->format('H:i')
                : $this->horas->limite($evento, $esSalida);
            $porHora[$hora][] = $evento;
        }

        $alguna = false;

        foreach ($porHora as $hora => $grupo) {
            $resultado = $this->confirmarHora->confirmarEn($grupo, (string) $hora, $esSalida, false, $actor);

            if ($resultado->esError()) {
                $this->logger->warning('Bot: confirmar_hora no pudo apuntarla.', ['mensaje' => $mensajeEntranteId, 'hora' => $hora, 'error' => $resultado->error]);
                continue;
            }

            $alguna = true;
        }

        if (!$alguna) {
            return;
        }

        if ($parametros->texto('plantilla_respuesta') !== null) {
            $this->plantillas->execute($mensajeEntranteId, ParametrosDeAccion::desdeCrudo([
                'template_code' => $parametros->texto('plantilla_respuesta'),
            ]));
        }
    }
}
