<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\FusionadorDeHilos;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Qué teléfono queda de salida al unir dos hilos.
 *
 * El caso que lo pide, Camille Boisfer (12/10/2026): el hilo de Booking tenía un teléfono puesto a
 * mano; ella escribía desde otro, por WhatsApp, en su propio hilo. Unidos sin elegir, siguió de
 * salida el de Booking —sin ventana abierta— y Meta rechazó la respuesta del agente con 131047.
 *
 * Sin base: `telefonoPropuesto()` sólo lee las dos entidades.
 */
final class FusionadorDeHilosPropuestaTest extends TestCase
{
    private function fusionador(): FusionadorDeHilos
    {
        return new FusionadorDeHilos(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
        );
    }

    private function hilo(string $telefono, string $origen, ?DateTimeImmutable $ventana = null): MessageConversation
    {
        $hilo = new MessageConversation('pms_reserva', 'x');
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, $telefono, $origen));
        $hilo->setGuestPhone($telefono);

        // La ventana es del NÚMERO desde el 13/10/2026: se abre como lo hace la recepción.
        if ($ventana !== null) {
            $hilo->abrirVentanaWhatsappDe($telefono, $ventana);
        }

        return $hilo;
    }

    public function testSeProponeElNumeroQueTieneLaVentanaDeWhatsappAbierta(): void
    {
        $booking = $this->hilo('33635505384', 'manual');
        $whatsapp = $this->hilo('33769734264', 'whatsapp', new DateTimeImmutable('+20 hours'));

        self::assertSame('33769734264', $this->fusionador()->telefonoPropuesto($booking, $whatsapp));
        self::assertSame(
            '33769734264',
            $this->fusionador()->telefonoPropuesto($whatsapp, $booking),
            'Da igual cuál sobreviva: manda la ventana.',
        );
    }

    public function testSinVentanaAbiertaSeQuedaElDelSuperviviente(): void
    {
        $viejo = $this->hilo('33635505384', 'manual');
        $nuevo = $this->hilo('33769734264', 'whatsapp', new DateTimeImmutable('-2 days'));

        self::assertSame('33635505384', $this->fusionador()->telefonoPropuesto($viejo, $nuevo));
    }

    public function testUnNumeroVetadoNoSeProponeAunqueTengaVentana(): void
    {
        $viejo = $this->hilo('33635505384', 'manual');
        $nuevo = $this->hilo('33769734264', 'whatsapp', new DateTimeImmutable('+20 hours'));
        foreach ($nuevo->getIdentidades() as $identidad) {
            $identidad->bloquear('131026: sin WhatsApp');
        }

        self::assertSame(['33635505384'], $this->fusionador()->telefonosCandidatos($viejo, $nuevo));
        self::assertSame('33635505384', $this->fusionador()->telefonoPropuesto($viejo, $nuevo));
    }

    public function testSinTelefonosNoSeProponeNada(): void
    {
        $a = new MessageConversation('pms_reserva', 'a');
        $b = new MessageConversation('pms_reserva', 'b');

        self::assertNull($this->fusionador()->telefonoPropuesto($a, $b));
    }
}
