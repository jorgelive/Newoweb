<?php

declare(strict_types=1);

namespace App\Tests\Pms\Command;

use App\Pms\Command\TipoCambioSincronizarCommand;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El retraso del tipo de cambio cuenta sólo hacia atrás.
 *
 * SUNAT publica el viernes la tasa del fin de semana y del lunes: el sábado la última cotización
 * va dos días POR DELANTE. `diff()->days` es absoluto, y hasta el 17/10/2026 eso disparaba la
 * alarma por WhatsApp cada fin de semana con el maestro al día.
 */
final class TipoCambioRetrasoTest extends TestCase
{
    /** @return iterable<string, array{string, string, int}> */
    public static function casos(): iterable
    {
        yield 'sábado con la del lunes publicada' => ['2026-10-19', '2026-10-17 08:40', 0];
        yield 'al día' => ['2026-10-17', '2026-10-17 08:40', 0];
        yield 'ayer, a primera hora' => ['2026-10-16', '2026-10-17 08:40', 1];
        yield 'dos días atrás' => ['2026-10-15', '2026-10-17 08:40', 2];
        yield 'noche de Lima, ya mañana en UTC' => ['2026-10-17', '2026-10-17 22:30', 0];
    }

    #[Test]
    #[DataProvider('casos')]
    public function cuenta_solo_hacia_atras(string $ultima, string $ahora, int $esperado): void
    {
        $lima = new DateTimeZone('America/Lima');
        // La fecha de la base llega a medianoche en la zona del proceso (UTC en producción).
        $deLaBase = new DateTimeImmutable($ultima, new DateTimeZone('UTC'));

        self::assertSame($esperado, TipoCambioSincronizarCommand::diasDeRetraso($deLaBase, new DateTimeImmutable($ahora, $lima)));
    }
}
