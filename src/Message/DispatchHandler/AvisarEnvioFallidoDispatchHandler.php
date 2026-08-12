<?php

declare(strict_types=1);

namespace App\Message\DispatchHandler;

use App\Message\Dispatch\AvisarEnvioFallidoDispatch;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Push\NotificadorPushConversacion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Un envío fallido hace dos cosas: se lo dice al operador y, si el motivo tiene arreglo, lo mueve.
 *
 * El único motivo con arreglo automático hoy es la **ventana de 24 h de WhatsApp**. El texto del
 * operador no puede salir —Meta sólo acepta plantillas fuera de la ventana, y una plantilla
 * lleva texto fijo, no lo que él escribió—, así que se le manda al huésped la plantilla
 * `ventana_cerrada` pidiéndole que conteste: **su respuesta reabre la ventana** y el operador
 * puede reenviar lo suyo.
 *
 * Todo esto vive en un worker y no en el listener a propósito: crear entidades dentro de un
 * flush de Doctrine es cómo se fabrican los estados imposibles. Aquí ya está todo confirmado.
 */
#[AsMessageHandler]
final readonly class AvisarEnvioFallidoDispatchHandler
{
    /** La plantilla que pide al huésped que conteste. Ver `Version20260812230000`. */
    private const string PLANTILLA_VENTANA = 'ventana_cerrada';

    /**
     * Trozo del motivo que delata el caso. Sale de `WhatsappMetaSendEnqueuer`, que lo escribe en
     * dos frases distintas —con plantilla no oficial y sin plantilla— y las dos lo contienen.
     */
    private const string SEÑAL_VENTANA = 'ventana de 24 horas';

    /**
     * No se avisa dos veces seguidas.
     *
     * Si el operador escribe tres mensajes a la misma conversación con la ventana cerrada, el
     * huésped recibiría tres avisos idénticos — y cada uno cuesta dinero, porque fuera de la
     * ventana Meta las cobra. Con uno basta: en cuanto conteste, se reabre para todo lo demás.
     */
    private const string VENTANA_ANTI_REPETICION = '-12 hours';

    public function __construct(
        private EntityManagerInterface $em,
        private NotificadorPushConversacion $notificador,
        private LoggerInterface $logger
    ) {}

    public function __invoke(AvisarEnvioFallidoDispatch $dispatch): void
    {
        $mensaje = $this->em->getRepository(Message::class)->find($dispatch->messageId);

        if (!$mensaje instanceof Message) {
            return;
        }

        // El worker es de vida larga y pudo cachear el mensaje de un trabajo anterior; además
        // entre la detección y este momento pudo reintentarse y salir.
        $this->em->refresh($mensaje);

        if ($mensaje->getStatus() !== Message::STATUS_FAILED) {
            return;
        }

        $this->notificador->avisarEnvioFallido($mensaje);

        if ($this->esPorLaVentana($mensaje)) {
            $this->pedirAlHuespedQueConteste($mensaje);
        }
    }

    /**
     * ¿Falló porque la ventana de 24 h estaba cerrada?
     *
     * Se mira el motivo que guardó `MessageDispatcher`, no el canal: el mismo mensaje puede
     * haber salido por Beds24 y haber fallado sólo en WhatsApp, y ese caso también cuenta —el
     * huésped de una OTA que además tiene WhatsApp merece enterarse por los dos sitios.
     */
    private function esPorLaVentana(Message $mensaje): bool
    {
        $metadata = $mensaje->getMetadata();
        $motivos = array_merge(
            is_array($metadata['dispatch_errors'] ?? null) ? $metadata['dispatch_errors'] : [],
            is_array($metadata['dispatch_partial_errors'] ?? null) ? $metadata['dispatch_partial_errors'] : []
        );

        foreach ($motivos as $motivo) {
            if (str_contains(mb_strtolower((string) $motivo), self::SEÑAL_VENTANA)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manda la plantilla que reabre la conversación, si se puede y si no se mandó ya.
     *
     * Sale como `SENDER_SYSTEM` y eso importa por dos motivos: no lo escribió una persona, y
     * `AvisoEnvioFallidoListener` sólo avisa de lo que escribe una persona — así que si este
     * aviso tampoco pudiera salir, no se dispararía un aviso del aviso.
     *
     * Los canales NO se pasan: con plantilla, `MessageDispatcher::resolveChannels()` toma los
     * suyos, y ésta sólo tiene WhatsApp activo.
     */
    private function pedirAlHuespedQueConteste(Message $fallido): void
    {
        $conversacion = $fallido->getConversation();

        if (!$conversacion instanceof MessageConversation) {
            return;
        }

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::PLANTILLA_VENTANA]);

        if (!$plantilla instanceof MessageTemplate) {
            $this->logger->warning(sprintf('[VentanaCerrada] No existe la plantilla «%s»; el huésped no recibe el aviso.', self::PLANTILLA_VENTANA));

            return;
        }

        $idioma = $conversacion->getIdioma()?->getId() ?? 'es';

        // Sin aprobar en Meta no se puede enviar fuera de la ventana, que es justo cuando hace
        // falta. Se dice en el log en vez de encolar algo que va a fallar seguro.
        if (!$plantilla->hasWhatsappMetaOfficialData(strtolower($idioma))) {
            $this->logger->warning(sprintf(
                '[VentanaCerrada] «%s» no está aprobada en Meta para «%s»: hay que subirla desde el panel.',
                self::PLANTILLA_VENTANA,
                $idioma
            ));

            return;
        }

        if ($this->yaSeAviso($conversacion, $plantilla)) {
            return;
        }

        $aviso = new Message();
        $aviso->setConversation($conversacion);
        $aviso->setDirection(Message::DIRECTION_OUTGOING);
        $aviso->setSenderType(Message::SENDER_SYSTEM);
        $aviso->setStatus(Message::STATUS_PENDING);
        $aviso->setTemplate($plantilla);
        $aviso->setLanguageCode(strtolower($idioma));
        $aviso->addMetadata('generado_por', 'ventana_cerrada');

        $conversacion->addMessage($aviso);
        $this->em->persist($aviso);
        $this->em->flush();

        $this->logger->info(sprintf(
            '[VentanaCerrada] Aviso enviado a «%s»: el mensaje de %s no salió y se le pide que conteste.',
            $conversacion->getGuestName() ?? '?',
            $fallido->getId()?->toRfc4122() ?? '?'
        ));
    }

    /**
     * ¿Ya se le pidió que conteste hace poco?
     *
     * Se consulta la BASE DE DATOS y no la colección del mensaje: la colección en memoria puede
     * venir a medio hidratar del worker, y ésta es exactamente la clase de comprobación que
     * fallaba en silencio antes del barrido de parámetros UUID —de ahí el tipo explícito.
     */
    private function yaSeAviso(MessageConversation $conversacion, MessageTemplate $plantilla): bool
    {
        $desde = new DateTimeImmutable(self::VENTANA_ANTI_REPETICION);

        $previos = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Message::class, 'm')
            ->where('m.conversation = :conversacion')
            ->andWhere('m.template = :plantilla')
            ->andWhere('m.createdAt >= :desde')
            ->andWhere('m.status != :cancelado')
            ->setParameter('conversacion', $conversacion->getId(), UuidType::NAME)
            ->setParameter('plantilla', $plantilla->getId(), UuidType::NAME)
            ->setParameter('desde', $desde)
            ->setParameter('cancelado', Message::STATUS_CANCELLED)
            ->getQuery()
            ->getSingleScalarResult();

        return $previos > 0;
    }
}
