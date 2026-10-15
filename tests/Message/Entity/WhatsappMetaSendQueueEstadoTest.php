<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\WhatsappMetaSendQueue as Cola;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo que la cola guarda de los avisos de estado de Meta. Ver
 * `WhatsappMetaSendQueue::registrarEstadoDeMeta()`: hasta el 15/10/2026 no se escribía nunca.
 */
final class WhatsappMetaSendQueueEstadoTest extends TestCase
{
    private function enviada(): Cola
    {
        $cola = new Cola();
        $cola->markSuccess(new DateTimeImmutable('2026-10-06 17:41:38'));

        return $cola;
    }

    #[Test]
    public function entregado_y_leido_quedan_en_la_cola_con_su_hora(): void
    {
        $cola = $this->enviada();
        $cola->registrarEstadoDeMeta('sent', new DateTimeImmutable('2026-10-06 17:41:39'));
        $cola->registrarEstadoDeMeta('delivered', new DateTimeImmutable('2026-10-06 17:41:40'));
        $cola->registrarEstadoDeMeta('read', new DateTimeImmutable('2026-10-06 17:41:46'));

        self::assertSame(Cola::DELIVERY_READ, $cola->getDeliveryStatus());
        self::assertSame('17:41:40', $cola->getDeliveredAt()?->format('H:i:s'));
        self::assertSame('17:41:46', $cola->getReadAt()?->format('H:i:s'));
        self::assertSame(['sent', 'delivered', 'read'], array_column($cola->getWebhookHistory(), 'status'));
    }

    #[Test]
    public function un_aviso_atrasado_no_baja_el_estado(): void
    {
        // Meta no garantiza el orden: el `delivered` puede llegar después del `read`.
        $cola = $this->enviada();
        $cola->registrarEstadoDeMeta('read', new DateTimeImmutable('2026-10-06 17:41:46'));
        $cola->registrarEstadoDeMeta('delivered', new DateTimeImmutable('2026-10-06 17:41:40'));

        self::assertSame(Cola::DELIVERY_READ, $cola->getDeliveryStatus());
        self::assertSame('17:41:40', $cola->getDeliveredAt()?->format('H:i:s'), 'La entrega real es la más temprana.');
    }

    #[Test]
    public function leido_implica_entregado(): void
    {
        $cola = $this->enviada();
        $cola->registrarEstadoDeMeta('read', new DateTimeImmutable('2026-10-06 17:41:46'));

        self::assertNotNull($cola->getDeliveredAt());
    }

    #[Test]
    public function un_fallo_queda_en_el_historial_sin_tocar_la_entrega(): void
    {
        $cola = $this->enviada();
        $cola->registrarEstadoDeMeta('failed', new DateTimeImmutable('2026-10-06 17:41:41'), '131026: Message undeliverable');

        self::assertSame(Cola::DELIVERY_SUBMITTED, $cola->getDeliveryStatus(), 'El envío a la API sí salió; el fallo ya está en el mensaje.');
        self::assertSame('131026: Message undeliverable', $cola->getWebhookHistory()[0]['error'] ?? null);
    }
}
