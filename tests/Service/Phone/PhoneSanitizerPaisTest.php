<?php

declare(strict_types=1);

namespace App\Tests\Service\Phone;

use App\Service\Phone\PhoneSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * De qué país es un número: la evidencia con la que se corrige el `country2` de Airbnb.
 *
 * Los casos con nombre y apellido salen de payloads REALES de `pms_beds24_webhook_audit`
 * (19/08/2026), todos marcados `country2: "ES"` por Airbnb. Ninguno es español.
 *
 * Unitario puro: `PhoneSanitizer` no tiene dependencias.
 */
final class PhoneSanitizerPaisTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function numeros(): iterable
    {
        yield 'peruano marcado ES por Airbnb (Ximena Oviedo)' => ['51973587482', 'PE'];
        yield 'colombiano marcado ES por Airbnb (S. Jiménez)' => ['573154518832', 'CO'];
        yield 'mexicano marcado ES por Airbnb (A. Cárdenas)'  => ['528119100902', 'MX'];
        yield 'español de verdad'                             => ['34612345678', 'ES'];
        yield 'con separadores y +'                           => ['+51 973 587 482', 'PE'];

        // Sin prefijo internacional NO se concluye. Es la mitad importante: si aquí se
        // devolviera algo, `resolvePais()` estaría convirtiendo una suposición en un dato.
        yield 'móvil peruano SIN prefijo'   => ['993464776', null];
        yield 'nueve dígitos ambiguos'      => ['912345678', null];
        yield 'vacío'                       => ['', null];
        yield 'sólo espacios'               => ['   ', null];
        yield 'el country basura del payload' => ['19', null];
        yield 'letras'                      => ['no-es-un-numero', null];
    }

    #[Test]
    #[DataProvider('numeros')]
    public function el_prefijo_dice_el_pais_o_calla(string $numero, ?string $esperado): void
    {
        self::assertSame($esperado, (new PhoneSanitizer())->paisDelNumero($numero));
    }
}
