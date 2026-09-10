<?php

declare(strict_types=1);

namespace App\Message\EventListener;

use App\Message\Dispatch\AvisarEnvioFallidoDispatch;
use App\Message\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cuando el mensaje que escribió una persona no consigue salir, se le dice.
 *
 * Hasta ahora un envío fallido no avisaba a nadie: el mensaje se quedaba en `FAILED` en la base
 * de datos y quien lo escribió se iba convencido de que había llegado. El caso que lo motivó
 * está contado en {@see \App\Message\Service\Push\NotificadorPushConversacion::avisarEnvioFallido()}.
 *
 * ── Los dos caminos hasta `FAILED`, y por eso dos eventos ───────────────────
 * - `postPersist`: el mensaje nace fallido. `MessageDispatcher::dispatch()` corre en el
 *   `prePersist` del enqueuer y puede no generar ninguna cola —canal no permitido, ventana de
 *   24 h cerrada, mensaje vacío—, así que el INSERT ya lleva el estado.
 * - `postUpdate`: nació encolado y murió después, cuando `resolveMessageStatus()` ve que todas
 *   las colas fracasaron.
 *
 * ⚠️ En el update se compara el CHANGESET, no el estado actual. Sin eso, cualquier otra
 * escritura sobre un mensaje que ya estaba fallido —marcar leído, guardar metadata— volvería a
 * disparar el aviso. Es exactamente el fallo que ya se pagó en
 * `MessageAutoResponderListener`, y aquí el síntoma sería un móvil sonando en bucle.
 *
 * ── A quién cubre, y por qué no a todos ────────────────────────────────────
 * Mensajes SALIENTES que alguien está esperando ahora mismo:
 *
 * - Los que escribe una **persona** (`SENDER_HOST`). Los escribió y se fue creyendo que salieron.
 * - Los que escribe el **agente** (`SENDER_SYSTEM` con `generado_por = 'ia'`), añadidos el
 *   10/09/2026. Son la respuesta a un huésped que acaba de preguntar y sigue esperando; que
 *   nadie se entere de que no salió es el mismo daño, con el agravante de que aquí no hay
 *   ninguna persona que sospeche.
 *
 * Los **automáticos programados** (check-out, guía de llegada, bienvenidas) siguen fuera, y no
 * por olvido: avisar de cada uno llenaría el móvil del equipo con algo que no puede arreglar en
 * el momento. Son 302 en seis meses, ~1,6 al día, y su sitio natural es un resumen, no un
 * empujón por cada uno.
 *
 * ⚠️ La cuenta que sostiene el reparto (10/09/2026): de 305 fallidos de `system`, **302 son de
 * regla programada y 3 del agente**. Cubrir al agente no es abrir la puerta, es dejar pasar
 * tres avisos en medio año — los dos de Katherine entre ellos, que es el caso que lo motivó:
 * la huésped preguntó por las camas, el agente contestó, Beds24 rechazó el envío por el bug del
 * canal, y nadie lo supo hasta que alguien abrió el chat a mirar.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: Message::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: Message::class)]
final readonly class AvisoEnvioFallidoListener
{
    public function __construct(private MessageBusInterface $bus) {}

    public function postPersist(Message $mensaje, PostPersistEventArgs $evento): void
    {
        if ($mensaje->getStatus() !== Message::STATUS_FAILED) {
            return;
        }

        $this->avisar($mensaje);
    }

    public function postUpdate(Message $mensaje, PostUpdateEventArgs $evento): void
    {
        $cambios = $evento->getObjectManager()->getUnitOfWork()->getEntityChangeSet($mensaje);

        // Sólo la TRANSICIÓN a fallido. Ver el aviso de la cabecera.
        if (!array_key_exists('status', $cambios) || $cambios['status'][1] !== Message::STATUS_FAILED) {
            return;
        }

        $this->avisar($mensaje);
    }

    /**
     * El envío del push va por el bus: esto corre dentro de un flush de Doctrine y mandar una
     * notificación es I/O de red. Mismo criterio que `MessageConversationMercureListener`.
     */
    private function avisar(Message $mensaje): void
    {
        if ($mensaje->getDirection() !== Message::DIRECTION_OUTGOING
            || $mensaje->getId() === null
            || !$this->hayAlguienEsperando($mensaje)) {
            return;
        }

        $this->bus->dispatch(new AvisarEnvioFallidoDispatch((string) $mensaje->getId()));
    }

    /**
     * ¿Hay alguien esperando este mensaje concreto, o es un automático de agenda?
     *
     * Es la pregunta que decide el aviso, y se responde por QUIÉN lo escribió, no por el canal
     * ni por el motivo del fallo: un check-out que no sale se arregla mañana; una respuesta a
     * quien preguntó hace un minuto, no.
     */
    private function hayAlguienEsperando(Message $mensaje): bool
    {
        if ($mensaje->getSenderType() === Message::SENDER_HOST) {
            return true;
        }

        return $mensaje->getSenderType() === Message::SENDER_SYSTEM
            && ($mensaje->getMetadata()['generado_por'] ?? null) === 'ia';
    }
}
