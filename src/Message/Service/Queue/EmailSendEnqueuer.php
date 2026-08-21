<?php

declare(strict_types=1);

namespace App\Message\Service\Queue;

use App\Exchange\Entity\EmailConfig;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\EmailSendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Conversacion\AliasDePlataforma;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Pone un mensaje en la cola de correo.
 *
 * ── El destino: de la persona, salvo cuando es de la plataforma ─────────────
 * No hay un `guestEmail` en la conversación, y es mejor así: hay casos en que «el correo de esa
 * persona» sencillamente no existe. Booking emite **un alias por reserva**, así que alguien con
 * dos estancias tiene dos.
 *
 * Así que hay dos regímenes y no uno: en una reserva de OTA manda el alias del asunto y no hay
 * respaldo —sin alias, el canal se apaga—; en todo lo demás manda el correo que una persona haya
 * marcado como **principal**, y el del asunto es sólo la semilla de cuando aún no había otro.
 * Ver `destino()`.
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
        private AliasDePlataforma $alias,
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

        // El marcador. Ver `EmailSendQueue::$endpoint`: el motor agrupa por él en SQL nativo.
        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)
            ->findOneBy(['provider' => ConnectivityProvider::EMAIL, 'accion' => 'email_send']);

        if ($endpoint === null) {
            throw new RuntimeException('Falta el endpoint `email_send`: el motor no puede armar el lote sin él.');
        }

        $cola = new EmailSendQueue();
        $cola->setMessage($message);
        $cola->setConfig($config);
        $cola->setEndpoint($endpoint);
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

        // ⚠️ Las CANCELADAS no cuentan, igual que en Beds24 y WhatsApp. Contándolas, un canal
        // que se podó y se vuelve a marcar no se recrearía nunca: la barrera diría «ya existe»
        // sobre una cola muerta, y el correo no saldría sin que nada lo dijera.
        foreach ($message->getEmailSendQueues() as $cola) {
            if ($cola->getStatus() !== EmailSendQueue::STATUS_CANCELLED) {
                return true;
            }
        }

        return false;
    }

    public function isValid(Message $message): bool
    {
        $conversation = $message->getConversation();

        // ⚠️ **Con el asunto del mensaje delante.** Preguntándolo a secas, un hilo con dos
        // estancias de OTA y ningún correo personal no tiene destino —no se puede elegir por
        // adivinación— y el canal se declaraba inválido aunque el mensaje SÍ dijera de cuál
        // habla. El motor lo podaba y el correo no salía, sin nada que lo explicara.
        return $conversation !== null
            && $this->disponiblePara($conversation, $message->getAsuntoType(), $message->getAsuntoId());
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
     * ── Lo que decide NO es el canal, es de quién es la dirección ───────────
     * ```
     * dirección de la PLATAFORMA   → el alias del asunto, y punto
     *   (asunto con correoEsExclusivo())   sin alias ⇒ null ⇒ el canal se apaga
     *
     * dirección de la PERSONA      → 1. el identificador PRINCIPAL
     *   (todo lo demás)                   2. el correo que sembró el asunto
     * ```
     *
     * ── Por qué el principal manda ahora, y antes no ────────────────────────
     * Esto ponía el asunto primero **siempre**, con el principal de respaldo. Era la regla de la
     * OTA aplicada a todo el mundo, y para una reserva directa está al revés: el correo del
     * asunto es un dato **sembrado al crearla** —lo que alguien tecleó una vez— y el principal es
     * lo que una persona marcó mirando la ficha, muchas veces después de que el primero rebotara.
     * Con el orden viejo, marcar un correo como principal no cambiaba nada mientras hubiera un
     * asunto elegido: el editor de identidades parecía funcionar y no servía para nada.
     *
     * ── Y por qué la OTA no tiene respaldo ──────────────────────────────────
     * ⚠️ Es la única rama del módulo donde «no hay dato» significa **no enviar**. Escribirle al
     * correo personal de un huésped de Booking saca la conversación de la plataforma —que es
     * justo lo que ésta penaliza— y además rompe la promesa de que el hilo de esa reserva vive
     * donde la reserva. Antes se caía al principal en silencio; ahora el canal se apaga y el
     * panel lo enseña apagado, que es visible.
     *
     * Sin asunto elegido y con uno solo en el hilo, ése decide: no hay con qué equivocarse.
     */
    private function destino(MessageConversation $conversacion, ?string $asuntoType, ?string $asuntoId): ?string
    {
        $elegido = $this->asuntoElegido($conversacion, $asuntoType, $asuntoId);

        if ($elegido?->correoEsExclusivo() === true) {
            return $elegido->correoDeContacto();
        }

        // ⚠️ **Sin asunto resuelto y con alguno exclusivo en el hilo, no se envía.**
        //
        // Ésta era la puerta de atrás: `asuntoElegido()` devuelve `null` con dos asuntos y
        // ninguno elegido, y `AsuntoDelMensaje::estampar()` deja el asunto en `NULL` justo en ese
        // caso — así que un mensaje del agente en el hilo de un huésped repetidor de Booking
        // caía al correo personal y sacaba la conversación de la plataforma. Exactamente lo que
        // la rama de arriba viene a impedir, esquivado por no saber de qué estancia se hablaba.
        //
        // Apagado, el panel lo enseña apagado y basta con elegir el asunto para encenderlo.
        if ($elegido === null && $this->alias->elHiloTieneAsuntosExclusivos($conversacion)) {
            return null;
        }

        // ⚠️ Y el principal **no vale si es un alias**. `getCorreoPrincipal()` devuelve también
        // el único correo vivo aunque nadie lo haya marcado, así que en un hilo donde el correo
        // personal se retiró por rebotar, el alias de Booking quedaba de «principal» de hecho
        // —sin que nadie lo marcara, que es justo lo que `marcarPrincipal()` impide a mano— y se
        // llevaba los envíos de TODOS los asuntos, incluidos los directos.
        $principal = $conversacion->getCorreoPrincipal();

        if ($principal !== null && !$this->alias->esAlias($conversacion, $principal)) {
            return $principal->getValor();
        }

        return $elegido?->correoDeContacto();
    }

    /**
     * De qué asunto va este correo, si es que se puede saber.
     *
     * El que digan `asuntoType`/`asuntoId`; y si no dicen nada pero el hilo sólo tiene uno, ése.
     * Con dos o más sin elegir se devuelve `null` a propósito: adivinar cuál es exactamente el
     * error que abre la puerta a mandarle a alguien el alias de la reserva equivocada.
     */
    private function asuntoElegido(
        MessageConversation $conversacion,
        ?string $asuntoType,
        ?string $asuntoId
    ): ?ConversacionEnlaceInterface {
        $asuntos = $this->enlaces->de($conversacion);

        if ($asuntoType !== null && $asuntoId !== null) {
            foreach ($asuntos as $asunto) {
                if ($asunto->getContextType() === $asuntoType && $asunto->getContextId() === $asuntoId) {
                    return $asunto;
                }
            }

            return null;
        }

        // ⚠️ Los CANCELADOS no cuentan, con el mismo criterio que `AsuntoDelMensaje::estampar()`.
        // Sin esto, «cliente que vuelve y tiene una cancelada en su historial» —el caso común, no
        // el raro— parece ambiguo: dos asuntos, ninguno elegible, y el botón de correo apagado en
        // un hilo que sólo tiene una reserva viva. Los dos sitios responden a la misma pregunta y
        // tienen que responderla igual.
        $vivos = array_values(array_filter(
            $asuntos,
            static fn (ConversacionEnlaceInterface $e): bool => $e->getVinculo() !== VinculoComercial::Terminado
        ));

        // Si no queda ninguno vivo se juzga con todos: a un hilo cuyas reservas terminaron todas
        // se le sigue pudiendo escribir del asunto que hubo.
        $candidatos = $vivos !== [] ? $vivos : $asuntos;

        return count($candidatos) === 1 ? $candidatos[0] : null;
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

        // Del asunto del mensaje si lo lleva; si no, del primero del hilo.
        //
        // ⚠️ Aquí decía que `EnlacesDeConversacion::de()` los devuelve «ordenados por
        // relevancia». **Es falso**: devuelve el orden de los proveedores (`createdAt ASC`). La
        // relevancia sólo la calcula `AsuntosDeConversacionController::comoLista()`. Así que este
        // respaldo toma la etiqueta del enlace MÁS ANTIGUO — aceptable como último recurso, pero
        // que nadie construya encima creyendo la garantía que el comentario prometía.
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
