<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las columnas JSON de `Message` y `MessageConversation` las hidrata Doctrine sin pasar por los
 * setters: lo que lleven lo decide quien escribió la fila, no el tipo. Los getters leen con
 * comprobación y lo que no encaja es «no está», en vez de un `TypeError` al serializar la
 * bandeja entera.
 *
 * En producción todo tiene la forma esperada (medido el 26/09/2026); esto fija qué pasa el día
 * que no.
 */
final class JsonDeEntidadesTest extends TestCase
{
    #[Test]
    public function el_contexto_bien_formado_se_lee_igual_que_antes(): void
    {
        $c = new MessageConversation('pms_reserva', 'r1');
        $c->setContextData([
            'origin' => 'airbnb',
            'agency' => null,
            'status_tag' => 'confirmed',
            'milestones' => ['start' => '2026-10-01 15:00:00', 'end' => '2026-10-04 11:00:00'],
            'items' => ['Casita 1', 'Casita 3'],
            'financials' => ['total' => 350.5, 'is_cleared' => true],
        ]);

        self::assertSame('airbnb', $c->getContextOrigin());
        self::assertNull($c->getContextAgency());
        self::assertSame('confirmed', $c->getContextStatusTag());
        self::assertSame(['start' => '2026-10-01 15:00:00', 'end' => '2026-10-04 11:00:00'], $c->getContextMilestones());
        self::assertSame(['Casita 1', 'Casita 3'], $c->getContextItems());
        self::assertSame(350.5, $c->getContextFinancialTotal());
        self::assertTrue($c->getContextFinancialIsCleared());
    }

    #[Test]
    public function el_contexto_mal_formado_es_no_esta_y_no_revienta(): void
    {
        $c = new MessageConversation('pms_reserva', 'r1');
        $c->setContextData([
            'origin' => 12,
            'milestones' => 'ayer',
            'items' => ['Casita 1', ['x'], 3],
            'financials' => ['total' => '120.50'],
        ]);

        self::assertNull($c->getContextOrigin());
        self::assertSame([], $c->getContextMilestones());
        self::assertSame(['Casita 1'], $c->getContextItems());
        self::assertSame(120.5, $c->getContextFinancialTotal(), 'el importe en texto se leía con `(float)`, y se sigue leyendo');
        self::assertFalse($c->getContextFinancialIsCleared());
    }

    #[Test]
    public function la_metadata_del_mensaje_se_lee_con_tipo(): void
    {
        $m = new Message();
        $m->setMetadata([
            'beds24' => ['sent_at' => '2026-09-26T10:00:00+00:00', 'read_at' => 1727344800],
            'whatsappMeta' => ['error_code' => '131047'],
            'variables_plantilla' => ['guest_name' => 'Ana', 'noches' => 3, 'lista' => ['a']],
            'inbound_intent' => ['resolved' => false],
        ]);

        self::assertSame('2026-09-26T10:00:00+00:00', $m->getBeds24SentAt());
        self::assertNull($m->getBeds24ReadAt(), 'un número donde va una fecha en texto era un TypeError');
        self::assertSame('131047', $m->getWhatsappMetaErrorCode());
        self::assertNull($m->getWhatsappMetaSentAt());
        self::assertSame(['guest_name' => 'Ana', 'noches' => 3], $m->getVariablesPlantilla(), 'un array habría salido como «Array» en el mensaje');
        self::assertSame(['resolved' => false], $m->getInboundIntent());
    }

    /** `ProcessInboundIntentDispatchHandler` distingue «no hay intención» de «está vacía». */
    #[Test]
    public function sin_intencion_es_null_y_no_un_array_vacio(): void
    {
        $m = new Message();

        self::assertNull($m->getInboundIntent());

        $m->setMetadata(['inbound_intent' => 'no soy un objeto']);
        self::assertNull($m->getInboundIntent());
    }
}
