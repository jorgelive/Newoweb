<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\EmailConfig;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\EmailSendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Pone un mensaje en la cola de correo.
 *
 * ── El destino sale del ASUNTO, y si no, de las identidades ─────────────────
 * No hay un `guestEmail` en la conversación, y es mejor así: hay casos en que «el correo de esa
 * persona» sencillamente no existe. Booking emite **un alias por reserva**, así que alguien con
 * dos estancias tiene dos, y elegir por orden de llegada es escribirle al hilo equivocado de la
 * plataforma. Manda el asunto; el identificador principal es el respaldo. Ver `destino()`.
 *
 * ⚠️ Se **congela** en la cola. Entre encolar y enviar pueden pasar días —un recordatorio se
 * programa con antelación— y en ese rato el correo puede cambiar o retirarse. Un mensaje sale a
 * donde se decidió que saliera. Es lo mismo que hace WhatsApp con `destinationPhone`.
 *
 * ── El asunto, y de dónde sale cuando no hay plantilla ──────────────────────
 * De la plantilla si la hay (`emailTmpl.subject`, que se traduce solo). Y si es texto libre, de
 * la **etiqueta del asunto** —«Tu reserva Casita 3, 12/03–15/03»—, que la redacta el dominio y
 * está pensada justo para eso: es lo único del enlace que puede acabar leyendo el cliente.
 *
 * Inventar aquí un título genérico sería peor: un correo sin contexto en la bandeja de alguien
 * que tiene tres reservas no dice de cuál va.
 */
final readonly class EmailSendEnqueuer implements ChannelEnqueuerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
    ) {
    }

    public function supports(MessageChannel $channel): bool
    {
        return $channel->getId() === 'email';
    }

    /**
     * Nunca devuelve `null`: si falta algo se lanza, con el motivo.
     *
     * El contrato admite `null` para «faltan datos», y `MessageDispatcher` lo trata como un
     * canal que no generó cola. Pero un correo sin destino y un correo sin buzón remitente son
     * dos cosas distintas, y las dos merecen decirse: el despachador recoge el mensaje de la
     * excepción y lo guarda en `dispatch_errors`, donde el panel lo enseña.
     */
    public function createQueueEntity(Message $message, MessageChannel $channel, DateTimeImmutable $runAt): MessageQueueItemInterface
    {
        $conversation = $message->getConversation();

        if ($conversation === null) {
            throw new RuntimeException('El mensaje no tiene una conversación asociada.');
        }

        $destino = $this->destino($conversation, $message->getAsuntoType(), $message->getAsuntoId());

        if ($destino === null) {
            throw new RuntimeException(sprintf(
                'No hay un correo al que escribir en la conversación %s.',
                $conversation->getId()?->toRfc4122() ?? '—'
            ));
        }

        $config = $this->em->getRepository(EmailConfig::class)->findOneBy(['activo' => true]);

        if ($config === null) {
            throw new RuntimeException('No hay ninguna configuración de correo activa: falta el buzón remitente.');
        }

        $cola = new EmailSendQueue();
        $cola->setMessage($message);
        $cola->setConfig($config);
        $cola->setDestinationEmail($destino);
        $cola->setSubject($this->asunto($message, $conversation));
        $cola->setRunAt($runAt);
        $cola->setStatus(EmailSendQueue::STATUS_PENDING);

        $message->addQueue($cola);
        $this->em->persist($cola);

        return $cola;
    }

    public function isAlreadyEnqueued(Message $message): bool
    {
        // También lo pendiente de insertar: el despachador puede encolar dos veces dentro del
        // mismo flush, y una consulta a secas no vería la primera.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof EmailSendQueue && $entidad->getMessage() === $message) {
                return true;
            }
        }

        return $message->getEmailSendQueues()->count() > 0;
    }

    public function isValid(Message $message): bool
    {
        $conversation = $message->getConversation();

        return $conversation !== null && $this->disponiblePara($conversation);
    }

    /** Sin correo al que escribir no hay canal. Ver el contrato. */
    public function disponiblePara(
        MessageConversation $conversacion,
        ?string $asuntoType = null,
        ?string $asuntoId = null
    ): bool {
        return $this->destino($conversacion, $asuntoType, $asuntoId) !== null;
    }

    /**
     * A qué correo se le escribe.
     *
     * ── El orden, y por qué ─────────────────────────────────────────────────
     * ```
     * 1. el correo del ASUNTO elegido      ← lo dice quien escribe: manda
     * 2. el identificador PRINCIPAL        ← lo marcó una persona a mano
     * 3. el correo del único asunto        ← no hay con qué equivocarse
     * ```
     *
     * El asunto va primero porque en una reserva de OTA el correo **es del asunto**: Booking
     * emite un alias por reserva, así que «el correo de esa persona» no existe cuando tiene dos
     * estancias. Elegir por orden de llegada sería mandarle el mensaje al hilo equivocado de la
     * plataforma.
     *
     * Y si no se dijo el asunto, manda el principal: eso lo marcó alguien mirando, y una
     * decisión de persona pesa más que una deducción.
     */
    private function destino(MessageConversation $conversacion, ?string $asuntoType, ?string $asuntoId): ?string
    {
        $asuntos = $this->enlaces->de($conversacion);

        if ($asuntoType !== null && $asuntoId !== null) {
            foreach ($asuntos as $asunto) {
                if ($asunto->getContextType() === $asuntoType && $asunto->getContextId() === $asuntoId) {
                    return $asunto->correoDeContacto() ?? $conversacion->getCorreoPrincipal()?->getValor();
                }
            }
        }

        if (($principal = $conversacion->getCorreoPrincipal()?->getValor()) !== null) {
            return $principal;
        }

        // Un solo asunto: su correo ES el de la conversación, sin ambigüedad que resolver.
        return count($asuntos) === 1 ? $asuntos[0]->correoDeContacto() : null;
    }

    /**
     * El título del correo, ya resuelto y congelado.
     *
     * Orden: lo que diga la plantilla en el idioma de la persona; si no, la etiqueta del asunto;
     * y si el hilo no tiene asuntos —un walk-in—, el nombre de quien escribe. Nunca vacío: un
     * correo sin asunto acaba en spam.
     */
    private function asunto(Message $message, MessageConversation $conversacion): string
    {
        $idioma = $conversacion->getIdioma()->getId() ?? 'es';
        $plantilla = $message->getTemplate();

        if ($plantilla instanceof MessageTemplate) {
            $subject = trim((string) $plantilla->getEmailSubject($idioma));

            if ($subject !== '') {
                return $subject;
            }
        }

        // Del asunto del mensaje si lo lleva; si no, del primero del hilo. `EnlacesDeConversacion`
        // los devuelve ordenados por relevancia, así que «el primero» es la estancia en curso o
        // la más próxima — la misma que propone el selector del panel.
        foreach ($this->enlaces->de($conversacion) as $enlace) {
            if ($message->getAsuntoId() !== null && $enlace->getContextId() !== $message->getAsuntoId()) {
                continue;
            }

            $etiqueta = trim($enlace->getEtiqueta());

            if ($etiqueta !== '') {
                return $etiqueta;
            }
        }

        $nombre = trim((string) $conversacion->getGuestName());

        return $nombre !== '' ? $nombre : 'Mensaje de OpenPeru';
    }
}
