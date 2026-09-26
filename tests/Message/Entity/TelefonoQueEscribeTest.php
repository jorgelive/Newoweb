<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El número que nos escribe pasa a ser el destino cuando el de antes no sirve.
 *
 * Nace del hilo de Or Cohen (24/09/2026): Airbnb dio un número sin WhatsApp, Meta lo vetó, Or
 * escribió desde otro con su localizador… y el hilo siguió bloqueado y escribiendo al muerto.
 * Ver `MessageConversation::preferirTelefonoQueEscribe()`.
 */
final class TelefonoQueEscribeTest extends TestCase
{
    #[Test]
    public function el_caso_de_or_el_numero_que_escribe_sustituye_al_vetado(): void
    {
        $hilo = $this->hilo();
        $this->telefono($hilo, '17073968456')->bloquear('Meta Error 131026: Message undeliverable');
        $hilo->setGuestPhone('17073968456');
        // El espejo como quedó en producción tras el 131026.
        $hilo->setWhatsappDisabled(true);
        $hilo->setWhatsappDisabledReason('Meta Error 131026: Message undeliverable');

        // Lo que hizo el localizador: unir el número nuevo, sin tocar nada más.
        $this->telefono($hilo, '972509814466');

        self::assertTrue($hilo->preferirTelefonoQueEscribe('972509814466'));
        self::assertSame('972509814466', $hilo->getGuestPhone(), 'Los envíos salen a `guestPhone`: si no cambia, seguimos escribiendo al número muerto.');
        self::assertFalse($hilo->isWhatsappDisabled(), 'Con un teléfono sano el hilo no puede seguir bloqueado.');
        self::assertSame('972509814466', $hilo->getTelefonoPrincipal()?->getValor());
    }

    #[Test]
    public function con_el_destino_sano_escribir_desde_otro_no_lo_cambia(): void
    {
        // Muy a menudo es el acompañante. Quien reservó sigue siendo a quien se escribe.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111');
        $hilo->setGuestPhone('51984111111');
        $this->telefono($hilo, '51984222222');

        self::assertFalse($hilo->preferirTelefonoQueEscribe('51984222222'));
        self::assertSame('51984111111', $hilo->getGuestPhone());
    }

    #[Test]
    public function un_numero_vetado_que_escribe_no_se_desveta_solo(): void
    {
        // Desvetar por un mensaje entrante es una decisión de persona, desde el panel.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111')->bloquear('Meta Error 131026');
        $hilo->setGuestPhone('51984111111');
        $hilo->recalcularBloqueoWhatsapp();

        self::assertFalse($hilo->preferirTelefonoQueEscribe('51984111111'));
        self::assertTrue($hilo->isWhatsappDisabled());
    }

    #[Test]
    public function sin_destino_el_que_escribe_pasa_a_serlo(): void
    {
        // El caso de Booking desde el 28/09: la reserva llega sin teléfono.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984222222');

        self::assertTrue($hilo->preferirTelefonoQueEscribe('+51 984 222 222'), 'El número llega con formato: se compara normalizado.');
        self::assertSame('51984222222', $hilo->getGuestPhone());
    }

    #[Test]
    public function un_destino_retirado_tampoco_sirve(): void
    {
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111')->retirar(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $hilo->setGuestPhone('51984111111');
        $this->telefono($hilo, '51984222222');

        self::assertTrue($hilo->preferirTelefonoQueEscribe('51984222222'));
        self::assertSame('51984222222', $hilo->getGuestPhone());
    }

    #[Test]
    public function el_veto_se_recalcula_aunque_el_destino_no_cambie(): void
    {
        // Un espejo desfasado —bloqueado con un número sano— se corrige al recibir, cambie o no
        // el destino. Era la mitad del hueco: el espejo sólo se movía desde el editor.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111');
        $hilo->setGuestPhone('51984111111');
        $hilo->setWhatsappDisabled(true);

        self::assertFalse($hilo->preferirTelefonoQueEscribe('51984111111'));
        self::assertFalse($hilo->isWhatsappDisabled());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(): MessageConversation
    {
        return new MessageConversation('pms_reserva', 'r-1');
    }

    private function telefono(MessageConversation $hilo, string $valor): MessageIdentidad
    {
        $identidad = new MessageIdentidad(IdentidadTipo::TELEFONO, $valor);
        $hilo->addIdentidad($identidad);

        return $identidad;
    }
}
