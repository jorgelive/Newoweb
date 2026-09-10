<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Queue;

use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Queue\MessageDispatcher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * El despachador escribe el desenlace en el mensaje que despacha — y hay un caso en el que ese
 * mensaje NO ES SUYO.
 *
 * Un ENTRANTE llega a `dispatch()` por una sola puerta: el acuse de lectura de
 * `MarkConversationReadController`, que le reinyecta su canal de origen para fabricar el recibo
 * hacia la OTA. Ahí el mensaje es la pregunta del huésped, recién marcada como `read`, y lo que
 * se está despachando es el RECIBO.
 *
 * Escribir el desenlace encima mentía de dos formas, las dos medidas en producción el
 * 10/09/2026: 17 preguntas de huéspedes en `failed` (con su icono rojo en el chat, y fuera de
 * cualquier consulta que filtre `received`/`read`) y 9 en `sent`. Se protege aquí porque ninguno
 * de los dos daba error en ninguna parte.
 */
final class MessageDispatcherTest extends TestCase
{
    // =========================================================================
    // DOBLES
    // =========================================================================

    /** Un encolador que siempre revienta, como `Beds24SendEnqueuer` ante una reserva directa. */
    private function encoladorQueSeNiega(string $canalId): ChannelEnqueuerInterface
    {
        return new class ($canalId) implements ChannelEnqueuerInterface {
            public function __construct(private readonly string $canalId) {}

            public function supports(MessageChannel $channel): bool
            {
                return $channel->getId() === $this->canalId;
            }

            public function createQueueEntity(Message $message, MessageChannel $channel, DateTimeImmutable $runAt): ?MessageQueueItemInterface
            {
                throw new RuntimeException('Operación denegada: No se permite enviar mensajes por la API de Beds24 a reservas directas.');
            }

            public function isAlreadyEnqueued(Message $message): bool { return false; }
            public function isValid(Message $message): bool { return true; }
            public function disponiblePara(MessageConversation $conversacion, ?string $asuntoType = null, ?string $asuntoId = null): bool { return false; }
        };
    }

    /** Un encolador que sí produce su cola: el recibo de lectura que SÍ sale. */
    private function encoladorQueEncola(string $canalId): ChannelEnqueuerInterface
    {
        $cola = $this->createStub(MessageQueueItemInterface::class);

        return new class ($canalId, $cola) implements ChannelEnqueuerInterface {
            public function __construct(
                private readonly string $canalId,
                private readonly MessageQueueItemInterface $cola
            ) {}

            public function supports(MessageChannel $channel): bool
            {
                return $channel->getId() === $this->canalId;
            }

            public function createQueueEntity(Message $message, MessageChannel $channel, DateTimeImmutable $runAt): ?MessageQueueItemInterface
            {
                return $this->cola;
            }

            public function isAlreadyEnqueued(Message $message): bool { return false; }
            public function isValid(Message $message): bool { return true; }
            public function disponiblePara(MessageConversation $conversacion, ?string $asuntoType = null, ?string $asuntoId = null): bool { return true; }
        };
    }

    /** @param list<ChannelEnqueuerInterface> $encoladores */
    private function despachador(array $encoladores, MessageChannel $canal): MessageDispatcher
    {
        $repositorio = $this->createStub(EntityRepository::class);
        $repositorio->method('findBy')->willReturn([$canal]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repositorio);

        // Sin proveedores de enlaces, `canalesPosibles()` no acota: el canal pedido llega entero
        // al bucle de encoladores, que es donde queremos mirar.
        return new MessageDispatcher($encoladores, $em, new NullLogger(), new EnlacesDeConversacion([]));
    }

    private function mensaje(string $direccion, string $estado): Message
    {
        $conversacion = new MessageConversation('pms_reserva', 'R-1');

        $mensaje = new Message();
        $mensaje->setConversation($conversacion);
        $mensaje->setDirection($direccion);
        $mensaje->setStatus($estado);
        $mensaje->setContentExternal('¿De cuántas plazas son las camas?');
        $mensaje->setTransientChannels(['beds24']);

        return $mensaje;
    }

    // =========================================================================
    // EL ENTRANTE NO SE TOCA
    // =========================================================================

    /** El caso de los 17: el recibo no sale y la pregunta del huésped acababa en `failed`. */
    #[Test]
    public function un_entrante_conserva_su_estado_aunque_el_recibo_fracase(): void
    {
        $canal = new MessageChannel()->setId('beds24')->setName('Beds24');
        $entrante = $this->mensaje(Message::DIRECTION_INCOMING, Message::STATUS_READ);

        $colas = $this->despachador([$this->encoladorQueSeNiega('beds24')], $canal)->dispatch($entrante);

        self::assertSame([], $colas);
        self::assertSame(Message::STATUS_READ, $entrante->getStatus(), 'La pregunta del huésped no falló: falló nuestro acuse.');
        self::assertArrayNotHasKey('dispatch_errors', $entrante->getMetadata(), 'Ni el icono rojo en la burbuja de quien preguntó.');
    }

    /** El caso de los 9, que era peor por silencioso: «enviado» sobre algo que escribió el huésped. */
    #[Test]
    public function un_entrante_conserva_su_estado_aunque_el_recibo_si_salga(): void
    {
        $canal = new MessageChannel()->setId('beds24')->setName('Beds24');
        $entrante = $this->mensaje(Message::DIRECTION_INCOMING, Message::STATUS_READ);

        $colas = $this->despachador([$this->encoladorQueEncola('beds24')], $canal)->dispatch($entrante);

        self::assertCount(1, $colas, 'El recibo se sigue encolando: lo que no se toca es el estado de quien escribió.');
        self::assertSame(Message::STATUS_READ, $entrante->getStatus());
    }

    // =========================================================================
    // CONTROL POSITIVO: EL SALIENTE SÍ SE MARCA
    // =========================================================================

    /**
     * Sin esto, las dos pruebas de arriba pasarían igual con un despachador que no marcara nada:
     * dirían «no se tocó» cuando lo cierto sería «no se evaluó».
     */
    #[Test]
    public function un_saliente_si_se_marca_fallido_con_su_motivo(): void
    {
        $canal = new MessageChannel()->setId('beds24')->setName('Beds24');
        $saliente = $this->mensaje(Message::DIRECTION_OUTGOING, Message::STATUS_PENDING);

        $this->despachador([$this->encoladorQueSeNiega('beds24')], $canal)->dispatch($saliente);

        self::assertSame(Message::STATUS_FAILED, $saliente->getStatus());
        self::assertStringContainsString(
            'reservas directas',
            implode(' ', $saliente->getMetadata()['dispatch_errors'] ?? []),
            'El motivo es lo único que le dice al operador qué hacer a continuación.'
        );
    }
}
