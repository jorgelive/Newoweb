<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El veto de WhatsApp es del NÚMERO, no de la persona.
 *
 * Estaba en `MessageConversation::$whatsappDisabled`, y ahí un número muerto apagaba el canal
 * para todo el hilo —desde la fusión, para todos sus asuntos—, incluido el número bueno de
 * quien tiene dos. El comando de fusión lo propagaba además por el lado pesimista.
 *
 * Meta rechaza un ENVÍO A UN DESTINO: el veto es de ese destino.
 */
final class BloqueoPorIdentidadTest extends TestCase
{
    #[Test]
    public function con_un_numero_muerto_y_otro_vivo_el_hilo_NO_queda_bloqueado(): void
    {
        // Es el caso entero de este cambio. Con la regla en «alguna», este hilo se quedaría sin
        // WhatsApp teniendo un número que funciona.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111')->bloquear('Meta Error 131026');
        $this->telefono($hilo, '51984222222');

        $hilo->recalcularBloqueoWhatsapp();

        self::assertFalse($hilo->isWhatsappDisabled());
        self::assertNull($hilo->getWhatsappDisabledReason());
    }

    #[Test]
    public function con_todos_muertos_el_hilo_si_queda_bloqueado_y_conserva_el_motivo(): void
    {
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111')->bloquear('Meta Error 131026: Message undeliverable');
        $this->telefono($hilo, '51984222222')->bloquear('Meta Error 131051');

        $hilo->recalcularBloqueoWhatsapp();

        self::assertTrue($hilo->isWhatsappDisabled());
        self::assertSame('Meta Error 131026: Message undeliverable', $hilo->getWhatsappDisabledReason());
    }

    #[Test]
    public function sin_ningun_telefono_no_es_bloqueo_sino_falta_de_datos(): void
    {
        // Confundirlos pintaría un aviso rojo de Meta donde sólo falta un dato. De que no haya
        // a dónde escribir ya se ocupa `WhatsappMetaSendEnqueuer::disponiblePara()`.
        $hilo = $this->hilo();
        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::EMAIL, 'nadie@ejemplo.com'));

        $hilo->recalcularBloqueoWhatsapp();

        self::assertFalse($hilo->isWhatsappDisabled());
    }

    #[Test]
    public function un_numero_retirado_no_cuenta_para_el_bloqueo(): void
    {
        // El equivocado que dio el huésped: se retira, y su veto deja de arrastrar al hilo.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111')
            ->bloquear('Meta Error 131026')
            ->retirar(new DateTimeImmutable('2026-08-20 12:00:00'));
        $this->telefono($hilo, '51984222222');

        $hilo->recalcularBloqueoWhatsapp();

        self::assertFalse($hilo->isWhatsappDisabled());
    }

    #[Test]
    public function la_lapida_impide_que_el_numero_retirado_vuelva_a_entrar(): void
    {
        // ⚠️ Es lo que hace posible retirar un número. `upsertFromContext()` re-registra los
        // identificadores del dominio en CADA recálculo, así que sin la fila presente el
        // siguiente pull de Beds24 lo resucitaría y retirar no serviría de nada.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111')->retirar(new DateTimeImmutable('2026-08-20 12:00:00'));

        $hilo->addIdentidad(new MessageIdentidad(IdentidadTipo::TELEFONO, '51984111111', 'contexto'));

        self::assertCount(1, $hilo->getIdentidades(), 'No se añade otra vez.');
        self::assertFalse($hilo->getIdentidades()->first()->estaViva(), 'Y sigue retirada.');
    }

    #[Test]
    public function retirar_es_idempotente_y_conserva_la_primera_fecha(): void
    {
        $primera = new DateTimeImmutable('2026-08-20 12:00:00');
        $identidad = new MessageIdentidad(IdentidadTipo::TELEFONO, '51984111111');

        $identidad->setPrincipal(true)->retirar($primera)->retirar(new DateTimeImmutable('2026-08-21 12:00:00'));

        self::assertSame($primera, $identidad->getRetiradoEn());
        self::assertFalse($identidad->isPrincipal(), 'Un número retirado no puede seguir siendo la salida por defecto.');
    }

    #[Test]
    public function el_telefono_principal_se_elige_por_marca_y_no_por_orden(): void
    {
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111');
        $this->telefono($hilo, '51984222222')->setPrincipal(true);

        self::assertSame('51984222222', $hilo->getTelefonoPrincipal()?->getValor());
    }

    #[Test]
    public function con_varios_y_ninguno_marcado_no_se_elige(): void
    {
        // Coger el primero sería mandarle el mensaje a quien resulte estar antes en la
        // colección, que no es una decisión: es un accidente reproducible.
        $hilo = $this->hilo();
        $this->telefono($hilo, '51984111111');
        $this->telefono($hilo, '51984222222');

        self::assertNull($hilo->getTelefonoPrincipal());
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
