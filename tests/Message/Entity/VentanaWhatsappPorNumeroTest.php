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

    /**
     * Hilo sin identidades: el número de salida usa la ventana del hilo también cuando se pregunta
     * POR NÚMERO, que es como pregunta el envío. Antes el envío decía «cerrada» y el encolador
     * «abierta» (Fable, 13/10/2026).
     */
    public function testEnUnHiloSinIdentidadesElNumeroDeSalidaUsaLaVentanaDelHilo(): void
    {
        $hilo = new MessageConversation('pms_reserva', 'x');
        $hilo->setGuestPhone('+' . self::RESERVA);
        $hilo->abrirVentanaWhatsappDe(self::RESERVA, new DateTimeImmutable('+20 hours'));

        self::assertTrue($hilo->ventanaWhatsappAbiertaPara(self::RESERVA));
        self::assertFalse($hilo->ventanaWhatsappAbiertaPara(self::WHATSAPP), 'Otro número no hereda la del hilo.');
    }

    /** Cambiar la salida a un número que no es identidad (nunca escribió) la deja cerrada. */
    public function testUnaSalidaNuevaSinIdentidadQuedaSinVentana(): void
    {
        $hilo = $this->hiloConDosNumeros();
        $hilo->abrirVentanaWhatsappDe(self::RESERVA, new DateTimeImmutable('+20 hours'));
        self::assertTrue($hilo->isWhatsappSessionActive());

        $hilo->setGuestPhone('51987654321');

        self::assertFalse($hilo->isWhatsappSessionActive(), 'Era la ventana del número ANTERIOR: 131047 seguro.');
    }

    /** Un número ajeno que escribe no abre la ventana del de salida. */
    public function testUnNumeroAjenoQueEscribeNoAbreLaVentanaDeLaSalida(): void
    {
        $hilo = $this->hiloConDosNumeros();

        $hilo->abrirVentanaWhatsappDe('51987654321', new DateTimeImmutable('+20 hours'));

        self::assertFalse($hilo->isWhatsappSessionActive());
    }

    /** `null` y «el número de salida escrito» son el mismo destino: un solo aviso de espera. */
    public function testElDestinoPorDefectoYElDeSalidaEscritoSonElMismo(): void
    {
        $hilo = $this->hiloConDosNumeros();

        self::assertSame(
            $hilo->destinoDe(new Message()),
            $hilo->destinoDe((new Message())->setTelefonoSalida('+' . self::RESERVA)),
        );
        self::assertNotSame($hilo->destinoDe(new Message()), $hilo->destinoDe((new Message())->setTelefonoSalida(self::WHATSAPP)));
    }

    public function testElEntranteGuardaDesdeQueNumeroEscribio(): void
    {
        $entrante = (new Message())->addMetadata('telefono_origen', self::WHATSAPP);

        self::assertSame(self::WHATSAPP, $entrante->getTelefonoOrigen());
        self::assertNull((new Message())->getTelefonoOrigen());
    }

    /**
     * Se le contesta al número que escribió (o pulsó el botón), no al principal. Hasta el
     * 17/10/2026 sólo lo hacía el agente: los botones miraban la ventana del principal y, si quien
     * pulsaba lo hacía desde su segundo número, la respuesta no salía.
     */
    public function testSeLeContestaAlNumeroQueEscribio(): void
    {
        $hilo = $this->hiloConDosNumeros();
        $desdeElSegundo = (new Message())->addMetadata('telefono_origen', self::WHATSAPP);

        self::assertSame(self::WHATSAPP, $hilo->telefonoParaResponderA($desdeElSegundo));
    }

    /** Desde el principal, o sin saber desde dónde: la salida de siempre (`null`). */
    public function testDesdeElPrincipalONoSabidoVaPorElDeSiempre(): void
    {
        $hilo = $this->hiloConDosNumeros();

        self::assertNull($hilo->telefonoParaResponderA((new Message())->addMetadata('telefono_origen', self::RESERVA)));
        self::assertNull($hilo->telefonoParaResponderA(new Message()));
    }

    /** Un número que no es de la persona, retirado o vetado no se usa nunca para responder. */
    public function testNuncaAUnNumeroAjenoRetiradoOVetado(): void
    {
        $hilo = $this->hiloConDosNumeros();
        self::assertNull($hilo->telefonoParaResponderA((new Message())->addMetadata('telefono_origen', '51999888777')));

        foreach ($hilo->getIdentidades() as $identidad) {
            if ($identidad->getValor() === self::WHATSAPP) {
                $identidad->bloquear('pidió que no le escribamos');
            }
        }
        self::assertNull($hilo->telefonoParaResponderA((new Message())->addMetadata('telefono_origen', self::WHATSAPP)));

        $otro = $this->hiloConDosNumeros();
        foreach ($otro->getIdentidades() as $identidad) {
            if ($identidad->getValor() === self::WHATSAPP) {
                $identidad->retirar(new DateTimeImmutable());
            }
        }
        self::assertNull($otro->telefonoParaResponderA((new Message())->addMetadata('telefono_origen', self::WHATSAPP)));
    }
}
