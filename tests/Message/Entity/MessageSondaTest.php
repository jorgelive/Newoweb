<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La sonda con la que se pregunta por los canales no puede entrar en la conversación: la colección
 * persiste en cascada y el siguiente `flush()` la guardaba como un mensaje vacío. Ver
 * `Message::sondaDeSalida()`.
 */
final class MessageSondaTest extends TestCase
{
    #[Test]
    public function laSondaConoceSuConversacionPeroNoSeApuntaEnElla(): void
    {
        $conversacion = new MessageConversation('pms_reserva', 'x');

        $sonda = Message::sondaDeSalida($conversacion);

        self::assertSame($conversacion, $sonda->getConversation());
        self::assertSame(Message::DIRECTION_OUTGOING, $sonda->getDirection());
        self::assertSame(Message::SENDER_HOST, $sonda->getSenderType());
        self::assertCount(0, $conversacion->getMessages());
    }

    #[Test]
    public function unMensajeDeVerdadSiSeApunta(): void
    {
        $conversacion = new MessageConversation('pms_reserva', 'x');

        (new Message())->setConversation($conversacion);

        self::assertCount(1, $conversacion->getMessages());
    }
}
