<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Entity\User;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Service\Conversacion\RemitenteDelEquipo;
use App\Repository\UserRepository;
use App\Service\Phone\PhoneSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El número de teléfono le da al agente los roles —con escritura— de alguien del equipo, así que
 * sólo cuenta el que alguien VERIFICA: el del remitente, en WhatsApp de Meta. Ver docs/Agent.md §2.
 */
#[CoversClass(RemitenteDelEquipo::class)]
final class RemitenteDelEquipoTest extends TestCase
{
    private const string OPERADOR = '51984000111';

    public function testPorWhatsappElRemitenteDelEquipoEsDelEquipo(): void
    {
        self::assertTrue($this->remitente()->esDelEquipo($this->mensaje('whatsapp_meta', self::OPERADOR)));
    }

    public function testPorElChatDeUnaOtaNoCuentaAunqueElNumeroSeaDelEquipo(): void
    {
        // El teléfono de un hilo de OTA lo teclea el huésped en el formulario de la reserva.
        self::assertFalse($this->remitente()->esDelEquipo($this->mensaje('beds24', self::OPERADOR)));
    }

    public function testSinRemitenteVerificadoNoSeCaeALaCabecera(): void
    {
        // Mensaje de WhatsApp sin `telefono_origen`: no se sabe quién escribió.
        self::assertFalse($this->remitente()->esDelEquipo($this->mensaje('whatsapp_meta', null)));
    }

    public function testUnHuespedPorWhatsappNoEsDelEquipo(): void
    {
        self::assertFalse($this->remitente()->esDelEquipo($this->mensaje('whatsapp_meta', '34600111222')));
    }

    private function remitente(): RemitenteDelEquipo
    {
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findByTelefono')->willReturnCallback(
            static fn (?string $telefono): ?User => $telefono === self::OPERADOR ? new User() : null,
        );

        return new RemitenteDelEquipo($usuarios, new PhoneSanitizer());
    }

    private function mensaje(string $canal, ?string $telefonoOrigen): Message
    {
        $mensaje = new Message();
        $mensaje->setChannel((new MessageChannel())->setId($canal));

        if ($telefonoOrigen !== null) {
            $mensaje->addMetadata('telefono_origen', $telefonoOrigen);
        }

        return $mensaje;
    }
}
