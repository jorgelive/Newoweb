<?php

declare(strict_types=1);

namespace App\Tests\Service\Nombre;

use App\Service\Nombre\NombreSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El nombre que llega gritado, y sobre todo el que NO hay que tocar.
 *
 * Los casos marcados «real» salen de `pms_beds24_webhook_audit` y de `pms_reserva`
 * (19/08/2026). La mitad de este test es la mitad defensiva: lo que ya venía bien escrito tiene
 * que salir byte a byte igual, porque «arreglar» un `Viana Da Silva` es romperlo.
 *
 * Unitario puro: la clase no tiene dependencias.
 */
final class NombreSanitizerTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function nombres(): iterable
    {
        yield 'el caso que motivó todo'      => ['QUISPE CONTRERAS', 'Quispe Contreras'];
        yield 'real: ILAY NAHARY (Booking)'  => ['ILAY NAHARY', 'Ilay Nahary'];
        yield 'real: PAULO LEANDRO (Booking)' => ['PAULO LEANDRO', 'Paulo Leandro'];
        yield 'partícula intermedia'         => ['DOSSO DE MORAES', 'Dosso de Moraes'];
        yield 'varias partículas'            => ['MARIA DE LA CRUZ', 'Maria de la Cruz'];
        yield 'la partícula que ABRE sube'   => ['DE LA CRUZ', 'De la Cruz'];
        yield 'tilde que sí está, se conserva' => ['JOSÉ GUILLERMO', 'José Guillermo'];
        yield 'apóstrofo'                    => ["O'BRIEN", "O'Brien"];
        yield 'guion'                        => ['JEAN-PIERRE', 'Jean-Pierre'];
        yield 'espacios de sobra'            => ['  ANA  ', 'Ana'];

        // ── Lo que NO se toca ────────────────────────────────────────────────────────
        yield 'real: ya bien escrito'        => ['Brunna Maura', 'Brunna Maura'];
        yield 'real: partícula que alguien escribió en mayúscula' => ['Viana Da Silva', 'Viana Da Silva'];
        yield 'mayúscula interior deliberada' => ['McDonald', 'McDonald'];
        yield 'real: apellido de UNA letra (Airbnb lo trunca)' => ['H', 'H'];
        yield 'sin letras'                   => ['123', '123'];
        yield 'vacío'                        => ['', ''];
        yield 'nulo'                         => [null, null];

        // La tilde que NO está en el dato no se inventa: es el límite conocido.
        yield 'límite: sin tilde entra, sin tilde sale' => ['JOSE', 'Jose'];
    }

    #[Test]
    #[DataProvider('nombres')]
    public function solo_se_corrige_lo_gritado(?string $entra, ?string $sale): void
    {
        self::assertSame($sale, (new NombreSanitizer())->formatear($entra));
    }

    #[Test]
    public function es_idempotente(): void
    {
        // La salida ya está en forma normal, así que la segunda pasada no la reconoce como
        // gritada y la devuelve intacta. Importa porque cada pull vuelve a pasar por aquí.
        $s = new NombreSanitizer();
        $una = $s->formatear('QUISPE CONTRERAS');

        self::assertSame($una, $s->formatear($una));
    }
}
