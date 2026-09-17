<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Engine;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use App\Exchange\Service\Contract\EndpointInterface;
use App\Exchange\Service\Engine\FiltroDeVetos;
use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\EmailSendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Pms\Entity\PmsBookingsPushQueue;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * La última puerta antes de la red: lo que ya no debe salir, no sale.
 *
 * El 17/09/2026 quedaron 25 colas vivas de 13 recordatorios cancelados, la primera para el día
 * siguiente a las 8:00. El worker elige por la cola y nada miraba el mensaje.
 */
final class FiltroDeVetosTest extends TestCase
{
    #[Test]
    public function un_mensaje_cancelado_no_sale_por_ninguna_de_sus_colas(): void
    {
        $cancelado = $this->mensaje(Message::STATUS_CANCELLED);
        $colas = [
            $this->cola(new Beds24SendQueue(), $cancelado),
            $this->cola(new WhatsappMetaSendQueue(), $cancelado),
            $this->cola(new EmailSendQueue(), $cancelado),
        ];

        self::assertNull($this->filtro()->apartar($this->lote($colas), 'prueba'));

        foreach ($colas as $cola) {
            self::assertSame('cancelled', $cola->getStatus());
            self::assertNotNull($cola->getFailedReason());
            // Sin soltar el candado, el vigilante de `claimRunnable()` la devolvería a `failed`.
            self::assertNull($cola->getLockedAt());
            self::assertNull($cola->getLockedBy());
        }
    }

    #[Test]
    public function del_lote_sale_solo_lo_vivo(): void
    {
        $vivo = $this->cola(new Beds24SendQueue(), $this->mensaje(Message::STATUS_QUEUED));
        $muerto = $this->cola(new Beds24SendQueue(), $this->mensaje(Message::STATUS_CANCELLED));

        $resto = $this->filtro()->apartar($this->lote([$vivo, $muerto]), 'prueba');

        self::assertNotNull($resto);
        self::assertSame([$vivo], $resto->getItems());
        self::assertSame('processing', $vivo->getStatus());
    }

    #[Test]
    public function un_mensaje_ya_enviado_por_otro_canal_sigue_saliendo_por_este(): void
    {
        // Beds24 salió primero y el mensaje ya dice `sent`: el WhatsApp todavía tiene que salir.
        $whatsapp = $this->cola(new WhatsappMetaSendQueue(), $this->mensaje(Message::STATUS_SENT));

        self::assertNotNull($this->filtro()->apartar($this->lote([$whatsapp]), 'prueba'));
        self::assertSame('processing', $whatsapp->getStatus());
    }

    #[Test]
    public function sin_canal_y_fallido_siguen_vivos(): void
    {
        foreach ([Message::STATUS_SIN_CANAL, Message::STATUS_FAILED, Message::STATUS_PENDING] as $estado) {
            $cola = $this->cola(new Beds24SendQueue(), $this->mensaje($estado));

            self::assertNotNull($this->filtro()->apartar($this->lote([$cola]), 'prueba'), $estado);
        }
    }

    #[Test]
    public function los_acuses_de_lectura_no_se_vetan(): void
    {
        // Viajan por las mismas colas como mensajes ENTRANTES ya leídos.
        $acuse = $this->cola(new Beds24SendQueue(), $this->mensaje(Message::STATUS_CANCELLED, Message::DIRECTION_INCOMING));

        self::assertNotNull($this->filtro()->apartar($this->lote([$acuse]), 'prueba'));
    }

    #[Test]
    public function las_colas_que_no_se_apuntan_no_cambian(): void
    {
        // `bookings_push` manda el estado ACTUAL, y una reserva cancelada TIENE que salir.
        $push = (new PmsBookingsPushQueue())->setStatus('processing');
        $lote = $this->lote([$push]);

        self::assertSame($lote, $this->filtro()->apartar($lote, 'prueba'));
        self::assertSame('processing', $push->getStatus());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function filtro(): FiltroDeVetos
    {
        return new FiltroDeVetos($this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    private function mensaje(string $estado, string $direccion = Message::DIRECTION_OUTGOING): Message
    {
        return (new Message())->setDirection($direccion)->setStatus($estado);
    }

    /**
     * @template T of Beds24SendQueue|WhatsappMetaSendQueue|EmailSendQueue
     * @param T $cola
     * @return T
     */
    private function cola(Beds24SendQueue|WhatsappMetaSendQueue|EmailSendQueue $cola, Message $mensaje): Beds24SendQueue|WhatsappMetaSendQueue|EmailSendQueue
    {
        $cola->setMessage($mensaje);
        // Recién reclamada: así la deja `claimRunnable()`.
        $cola->markProcessing('worker-prueba', new DateTimeImmutable());

        return $cola;
    }

    /**
     * @param list<\App\Exchange\Service\Contract\ExchangeQueueItemInterface> $items
     */
    private function lote(array $items): HomogeneousBatch
    {
        return new HomogeneousBatch(
            $this->createStub(ChannelConfigInterface::class),
            $this->createStub(EndpointInterface::class),
            $items,
        );
    }
}
