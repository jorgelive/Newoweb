<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageConversation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La promoción de un hilo WALK-IN a su asunto real.
 *
 * Un número no reconocido nace como `manual` (`WhatsappMetaReceivePersister`, rama D). Desde
 * que los hilos resuelven POR IDENTIDAD, cuando esa persona reserva la factory devuelve ese
 * mismo hilo en vez de crear uno nuevo —que es lo que se quiere— pero la cabecera se quedaba
 * en `manual`. Y el agente todavía decide por `getContextType()`: el huésped preguntaba por
 * su reserva y se le contestaba que no tiene ninguna, teniéndola enlazada.
 */
final class MessageConversationPromocionTest extends TestCase
{
    #[Test]
    public function el_walk_in_adopta_la_reserva_que_aparece_despues(): void
    {
        $c = new MessageConversation('manual', '+51987654321');

        self::assertTrue($c->promoverDesdeManual('pms_reserva', 'r-42'));
        self::assertSame('pms_reserva', $c->getContextType());
        self::assertSame('r-42', $c->getContextId());
    }

    #[Test]
    public function una_cabecera_con_asunto_real_no_se_pisa(): void
    {
        // El segundo asunto de una persona es un ENLACE más, no una cabecera nueva: reescribir
        // aquí haría que el hilo cambiara de reserva cada vez que se recalcula cualquiera.
        $c = new MessageConversation('pms_reserva', 'r-primera');

        self::assertFalse($c->promoverDesdeManual('pms_reserva', 'r-segunda'));
        self::assertSame('r-primera', $c->getContextId());
    }

    #[Test]
    public function no_se_degrada_un_hilo_manual_a_otro_manual(): void
    {
        // Sin este corte, un walk-in que vuelve a escribir se «promovería» a su propio
        // teléfono y el log se llenaría de promociones que no promueven nada.
        $c = new MessageConversation('manual', '+51987654321');

        self::assertFalse($c->promoverDesdeManual('manual', '+51900000000'));
        self::assertSame('+51987654321', $c->getContextId());
    }
}
