<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Queue;

use App\Message\Entity\Beds24SendQueue;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\WhatsappMetaSendQueue;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Queue\MessageRuleEngine;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * La curación del motor le da la razón a la cancelación, no a la cola que faltó cancelar.
 *
 * Deducía el estado de las colas, y ante una `pending` devolvía el cancelado a `queued`: cuando la
 * cascada de cancelación fallaba, la curación deshacía la cancelación y el mensaje salía.
 */
final class CuracionNoResucitaCanceladosTest extends TestCase
{
    #[Test]
    public function un_cancelado_con_cola_viva_sigue_cancelado_y_pierde_la_cola(): void
    {
        $mensaje = (new Message())->setStatus(Message::STATUS_CANCELLED);
        $cola = (new Beds24SendQueue())->setStatus('pending');
        $mensaje->addQueue($cola);

        $this->curar($mensaje);

        self::assertSame(Message::STATUS_CANCELLED, $mensaje->getStatus());
        self::assertSame('cancelled', $cola->getStatus());
    }

    #[Test]
    public function un_cancelado_que_ya_salio_por_un_canal_pasa_a_enviado(): void
    {
        // Eso es historia: el huésped lo recibió.
        $mensaje = (new Message())->setStatus(Message::STATUS_CANCELLED);
        $salio = (new Beds24SendQueue())->setStatus('success');
        $pendiente = (new WhatsappMetaSendQueue())->setStatus('pending');
        $mensaje->addQueue($salio)->addQueue($pendiente);

        $this->curar($mensaje);

        self::assertSame(Message::STATUS_SENT, $mensaje->getStatus());
        self::assertSame('success', $salio->getStatus());
        self::assertSame('cancelled', $pendiente->getStatus());
    }

    #[Test]
    public function un_encolado_con_cola_viva_no_cambia(): void
    {
        $mensaje = (new Message())->setStatus(Message::STATUS_QUEUED);
        $cola = (new Beds24SendQueue())->setStatus('pending');
        $mensaje->addQueue($cola);

        $this->curar($mensaje);

        self::assertSame(Message::STATUS_QUEUED, $mensaje->getStatus());
        self::assertSame('pending', $cola->getStatus());
    }

    private function curar(Message $mensaje): void
    {
        $hilo = new MessageConversation('pms_reserva', 'prueba');
        $hilo->addMessage($mensaje);

        $motor = new MessageRuleEngine(
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            [],
            new EnlacesDeConversacion([]),
        );

        new ReflectionMethod(MessageRuleEngine::class, 'healZombieMessages')
            ->invoke($motor, $hilo, new DateTimeImmutable('-12 hours'));
    }
}
