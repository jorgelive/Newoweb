<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Entity\User;
use App\Message\Dto\AutorDelMensaje;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Quién habló en cada saliente: lo que pinta el chat y lo que lee el agente en su historial. */
#[CoversClass(Message::class)]
final class MessageAutorEtiquetaTest extends TestCase
{
    public function testLaPersonaDelEquipoPorSuNombre(): void
    {
        $susan = (new User())->setUsername('susan')->setFirstname('Susan')->setLastname('Acuña');

        self::assertSame('Susan Acuña', $this->saliente(Message::SENDER_HOST)->setAutor($susan)->getAutorEtiqueta());
    }

    public function testLoQueEntraPorUnaIntegracionDiceDondeSeEscribio(): void
    {
        $mensaje = $this->saliente(Message::SENDER_HOST)->setChannel($this->canal('beds24'))->setMetadata(['escrito_en' => 'Booking.com']);

        self::assertSame('Escrito en Booking.com', $mensaje->getAutorEtiqueta());
        self::assertSame(AutorDelMensaje::EXTERNO, $mensaje->getAutorDelMensaje()?->tipo);
    }

    /** Sin `escrito_en` cae al nombre del canal: el núcleo no traduce `booking` a nada. */
    public function testSinDecirDondeCaeAlCanal(): void
    {
        $canal = $this->canal('beds24')->setName('Beds24');

        self::assertSame('Escrito en Beds24', $this->saliente(Message::SENDER_HOST)->setChannel($canal)->getAutorEtiqueta());
    }

    public function testDelEquipoSinAutorEsLoDeAntes(): void
    {
        self::assertSame('Equipo', $this->saliente(Message::SENDER_HOST)->getAutorEtiqueta());
    }

    public function testElAgenteYLoAutomatico(): void
    {
        self::assertSame('Agente', $this->saliente(Message::SENDER_SYSTEM)->setMetadata(['generado_por' => 'ia'])->getAutorEtiqueta());
        self::assertSame('Automático', $this->saliente(Message::SENDER_SYSTEM)->setTemplate(new MessageTemplate())->getAutorEtiqueta());
    }

    public function testLoDelHuespedNoLlevaEtiqueta(): void
    {
        self::assertNull($this->saliente(Message::SENDER_GUEST)->setDirection(Message::DIRECTION_INCOMING)->getAutorEtiqueta());
    }

    private function saliente(string $quien): Message
    {
        return (new Message())->setDirection(Message::DIRECTION_OUTGOING)->setSenderType($quien);
    }

    private function canal(string $id): MessageChannel
    {
        return (new MessageChannel())->setId($id);
    }
}
