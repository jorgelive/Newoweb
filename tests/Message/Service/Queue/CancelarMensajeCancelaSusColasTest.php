<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Queue;

use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Queue\MessageDispatcher;
use App\Message\Service\Queue\MessageRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Cancelar un mensaje cancela sus colas en el mismo acto, sin esperar a la cascada del listener.
 *
 * La cascada falla en silencio cuando el mensaje viene de una consulta: el 17/09/2026, al activar
 * la guía de llegada, 13 recordatorios de Booking quedaron `cancelled` con 25 colas en `pending`
 * — el texto viejo habría salido al día siguiente detrás del nuevo.
 */
final class CancelarMensajeCancelaSusColasTest extends TestCase
{
    #[Test]
    public function las_colas_vivas_se_cancelan_con_el_mensaje(): void
    {
        [$mensaje, $beds24, $meta] = $this->mensajeConColas('pending', 'pending');

        $this->cancelar($mensaje);

        self::assertSame(Message::STATUS_CANCELLED, $mensaje->getStatus());
        self::assertSame('cancelled', $beds24->getStatus());
        self::assertSame('cancelled', $meta->getStatus());
    }

    #[Test]
    public function lo_ya_enviado_no_se_reescribe(): void
    {
        // Una cola que ya salió es historia: cancelar el mensaje no la convierte en «cancelada».
        [$mensaje, $beds24, $meta] = $this->mensajeConColas('success', 'pending');

        $this->cancelar($mensaje);

        self::assertSame('success', $beds24->getStatus());
        self::assertSame('cancelled', $meta->getStatus());
    }

    #[Test]
    public function un_mensaje_que_no_esta_en_cola_no_se_toca(): void
    {
        [$mensaje, $beds24] = $this->mensajeConColas('pending', 'pending', Message::STATUS_SENT);

        $this->cancelar($mensaje);

        self::assertSame(Message::STATUS_SENT, $mensaje->getStatus());
        self::assertSame('pending', $beds24->getStatus());
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{Message, Beds24SendQueue, WhatsappMetaSendQueue}
     */
    private function mensajeConColas(string $beds24, string $meta, string $estado = Message::STATUS_QUEUED): array
    {
        $mensaje = (new Message())->setStatus($estado);
        $colaBeds24 = (new Beds24SendQueue())->setStatus($beds24);
        $colaMeta = (new WhatsappMetaSendQueue())->setStatus($meta);
        $mensaje->addQueue($colaBeds24)->addQueue($colaMeta);

        return [$mensaje, $colaBeds24, $colaMeta];
    }

    private function cancelar(Message $mensaje): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $motor = new MessageRuleEngine(
            $em,
            new NullLogger(),
            [],
            new EnlacesDeConversacion([]),
            new MessageDispatcher([], $em, new NullLogger(), new EnlacesDeConversacion([])),
        );

        new ReflectionMethod(MessageRuleEngine::class, 'cancelPendingQueues')->invoke($motor, $mensaje);
    }
}
