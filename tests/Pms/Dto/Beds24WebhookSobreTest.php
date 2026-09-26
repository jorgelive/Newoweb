<?php

declare(strict_types=1);

namespace App\Tests\Pms\Dto;

use App\Pms\Dto\Beds24WebhookSobre;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El paquete de un webhook de Beds24. Que coincide con el código de antes sobre los payloads reales
 * lo comprueba `tools/pruebas/probar-dto-beds24-webhook.php`; aquí, la prioridad del instante y el
 * huso, que es donde se equivocó una vez.
 */
#[CoversClass(Beds24WebhookSobre::class)]
final class Beds24WebhookSobreTest extends TestCase
{
    /** Las fechas de Beds24 llegan sin huso y son UTC: parsearlas locales corría el instante 5 h. */
    public function testLaFechaDeLaReservaSeLeeEnUtc(): void
    {
        $sobre = Beds24WebhookSobre::fromArray(['booking' => ['id' => 1, 'modifiedTime' => '2025-07-30T14:32:00']]);

        self::assertSame((new \DateTimeImmutable('2025-07-30 14:32:00', new \DateTimeZone('UTC')))->getTimestamp(), $sobre->momento);
    }

    public function testElUltimoMensajeDelAnfitrionMandaSobreLaReserva(): void
    {
        $sobre = Beds24WebhookSobre::fromArray([
            'booking' => ['id' => 1, 'modifiedTime' => '2025-07-30T14:32:00'],
            'messages' => [['id' => 9, 'source' => 'host', 'time' => '2025-07-30T20:00:00+00:00']],
        ]);

        self::assertSame((new \DateTimeImmutable('2025-07-30T20:00:00+00:00'))->getTimestamp(), $sobre->momento);
    }

    /** Un mensaje del huésped NO manda: se le quiere procesar cuanto antes, sin colchón por fecha. */
    public function testUnMensajeDelHuespedNoFijaElInstante(): void
    {
        $sobre = Beds24WebhookSobre::fromArray(['messages' => [['id' => 9, 'source' => 'guest', 'time' => '2025-07-30T20:00:00+00:00']]]);

        self::assertNull($sobre->momento);
    }

    public function testUnaReservaSueltaOUnaListaSeRepartenIgualYSinIdNoEntran(): void
    {
        self::assertCount(1, Beds24WebhookSobre::fromArray(['booking' => ['id' => 1]])->reservas);
        self::assertCount(2, Beds24WebhookSobre::fromArray(['booking' => [['id' => 1], ['id' => 2], ['sin' => 'id']]])->reservas);
        self::assertSame([], Beds24WebhookSobre::fromArray(['booking' => 'raro'])->reservas);
    }

    public function testLaEtiquetaSaleDeLaReserva(): void
    {
        $sobre = Beds24WebhookSobre::fromArray(['booking' => ['id' => 83116820, 'firstName' => 'Ana', 'lastName' => 'López', 'referer' => 'booking.com']]);

        self::assertSame('83116820', $sobre->reservaId);
        self::assertSame('Ana López', $sobre->huesped);
        self::assertSame('BOOKING.COM', $sobre->canal);
    }
}
