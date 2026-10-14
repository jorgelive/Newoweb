<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * La ventana de 24 h de WhatsApp es de cada NÚMERO, como la cuenta Meta.
 *
 * El caso que lo pide, Camille Boisfer (12/10/2026): escribía desde un número, el hilo «tenía
 * ventana», la respuesta salió al otro —el de su reserva, que nunca nos escribió— y Meta la
 * rechazó con 131047.
 */
final class VentanaWhatsappPorNumeroTest extends TestCase
{
    private const string RESERVA = '33635505384';
    private const string WHATSAPP = '33769734264';

    private function hiloConDosNumeros(): MessageConversation
    {
        $hilo = new MessageConversation('pms_reserva', 'x');
        $hilo->addIdentidad((new MessageIdentidad(IdentidadTipo::TELEFONO, self::RESERVA, 'manual'))->setPrincipal(true));
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, self::WHATSAPP, 'whatsapp'));
        $hilo->recalcularTelefonoPrincipal();

        return $hilo;
    }

    public function testEscribirDesdeUnNumeroNoAbreLaVentanaDelOtro(): void
    {
        $hilo = $this->hiloConDosNumeros();

        $hilo->abrirVentanaWhatsappDe(self::WHATSAPP, new DateTimeImmutable('+20 hours'));

        self::assertTrue($hilo->ventanaWhatsappAbiertaPara(self::WHATSAPP));
        self::assertFalse($hilo->ventanaWhatsappAbiertaPara(self::RESERVA));
        self::assertFalse(
            $hilo->isWhatsappSessionActive(),
            'La del hilo es la del número de salida (el principal), que no escribió: era el 131047 de Camille.',
        );
    }

    public function testCambiarElPrincipalTraeLaVentanaDelNumeroNuevo(): void
    {
        $hilo = $this->hiloConDosNumeros();
        $hilo->abrirVentanaWhatsappDe(self::WHATSAPP, new DateTimeImmutable('+20 hours'));

        foreach ($hilo->getIdentidades() as $identidad) {
            $identidad->setPrincipal($identidad->getValor() === self::WHATSAPP);
        }
        $hilo->recalcularTelefonoPrincipal();

        self::assertSame(self::WHATSAPP, $hilo->getGuestPhone());
        self::assertTrue($hilo->isWhatsappSessionActive());
    }

    public function testUnEntranteViejoNoAcortaLaVentana(): void
    {
        $hilo = $this->hiloConDosNumeros();
        $hilo->abrirVentanaWhatsappDe(self::RESERVA, new DateTimeImmutable('+20 hours'));
        $hilo->abrirVentanaWhatsappDe(self::RESERVA, new DateTimeImmutable('-1 hour'));

        self::assertTrue($hilo->ventanaWhatsappAbiertaPara(self::RESERVA));
    }

    public function testUnHiloSinIdentidadesSigueConLaVentanaDelHilo(): void
    {
        $hilo = new MessageConversation('pms_reserva', 'x');
        $hilo->setGuestPhone(self::RESERVA);

        $hilo->abrirVentanaWhatsappDe(self::RESERVA, new DateTimeImmutable('+20 hours'));

        self::assertTrue($hilo->isWhatsappSessionActive());
        self::assertTrue($hilo->ventanaWhatsappAbiertaPara(null));
    }

    public function testUnNumeroAjenoNoTieneVentana(): void
    {
        $hilo = $this->hiloConDosNumeros();
        $hilo->abrirVentanaWhatsappDe(self::RESERVA, new DateTimeImmutable('+20 hours'));

        self::assertFalse($hilo->ventanaWhatsappAbiertaPara('51999888777'));
    }

    public function testElNumeroDeSalidaDelMensajeSeNormalizaYSeGuarda(): void
    {
        $mensaje = (new Message())->setTelefonoSalida('+33 7 69 73 42 64');

        self::assertSame(self::WHATSAPP, $mensaje->getTelefonoSalida());
        self::assertSame(self::WHATSAPP, $mensaje->getMetadata()['telefono_salida'] ?? null);
        self::assertNull((new Message())->setTelefonoSalida('')->getTelefonoSalida());
    }
}
