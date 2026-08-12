<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Contract\VinculoComercial;
use App\Message\Entity\MessageConversation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El desbloqueo de WhatsApp al corregir el teléfono.
 *
 * `whatsappDisabled` lo pone el receptor cuando Meta rechaza un envío, y no había un solo
 * `setWhatsappDisabled(false)` en todo el proyecto: la conversación quedaba muerta para
 * WhatsApp de forma permanente. Lo que despistaba es que la re-evaluación de reglas SÍ
 * funcionaba —`guestPhone` está en los campos críticos del motor— y aun así no salía nada,
 * porque el enqueuer mira el flag y aborta antes de intentarlo. El veto gana.
 */
final class MessageConversationTest extends TestCase
{
    private static function bloqueada(string $telefono): MessageConversation
    {
        $c = new MessageConversation('pms_reserva', 'r1');
        $c->setGuestPhone($telefono);
        $c->setWhatsappDisabled(true)->setWhatsappDisabledReason('Meta Error 131026: Message undeliverable');

        return $c;
    }

    #[Test]
    public function cambiar_el_telefono_levanta_el_bloqueo(): void
    {
        $c = self::bloqueada('51999111222');

        $c->setGuestPhone('51958191965');

        self::assertFalse($c->isWhatsappDisabled(), 'el veto era sobre el número antiguo');
        self::assertNull($c->getWhatsappDisabledReason());
        self::assertSame('51958191965', $c->getGuestPhone());
    }

    /**
     * La precaución sin la que esto sería peor que el fallo: `upsertFromContext()` corre en
     * CADA mensaje entrante de Beds24 y reescribe el teléfono con el mismo valor. Sin esta
     * comparación, cualquier entrante desbloquearía un número legítimamente vetado.
     */
    #[Test]
    public function reescribir_el_mismo_telefono_no_desbloquea(): void
    {
        $c = self::bloqueada('51999111222');

        $c->setGuestPhone('51999111222');

        self::assertTrue($c->isWhatsappDisabled());
        self::assertSame('Meta Error 131026: Message undeliverable', $c->getWhatsappDisabledReason());
    }

    #[Test]
    public function borrar_el_telefono_tambien_es_un_cambio(): void
    {
        $c = self::bloqueada('51999111222');

        $c->setGuestPhone(null);

        self::assertFalse($c->isWhatsappDisabled());
    }

    #[Test]
    public function sin_bloqueo_previo_cambiar_el_telefono_no_altera_nada(): void
    {
        $c = new MessageConversation('pms_reserva', 'r2');
        $c->setGuestPhone('51999111222');

        $c->setGuestPhone('51958191965');

        self::assertFalse($c->isWhatsappDisabled());
        self::assertNull($c->getWhatsappDisabledReason());
    }

    /**
     * El snapshot del vínculo. `null` significa «no lo sé» —conversación anterior al campo—,
     * no «ninguno»: quien lo consuma debe caer a `deStatusTag()`, no dar por hecho que no hay
     * vínculo.
     */
    #[Test]
    public function el_vinculo_del_snapshot_distingue_ausencia_de_ninguno(): void
    {
        $c = new MessageConversation('pms_reserva', 'r3');

        self::assertNull($c->getContextVinculo(), 'sin upsert todavía, no hay dato');

        $c->setContextVinculo(VinculoComercial::Interesado);

        self::assertSame(VinculoComercial::Interesado, $c->getContextVinculo());
    }
}
