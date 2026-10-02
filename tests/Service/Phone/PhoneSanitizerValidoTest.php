<?php

declare(strict_types=1);

namespace App\Tests\Service\Phone;

use App\Service\Phone\PhoneSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El número que teclea el propio huésped en su página: válido o nada. Al contrario que
 * `cleanPhoneNumber()`, aquí no se guardan dígitos sueltos — quien escribe está delante y se le
 * puede pedir que lo revise.
 */
final class PhoneSanitizerValidoTest extends TestCase
{
    #[Test]
    public function con_prefijo_internacional_vale_sin_mirar_el_pais_de_la_reserva(): void
    {
        self::assertSame('33652307493', (new PhoneSanitizer())->validoONulo('+33 6 52 30 74 93', 'PE'));
    }

    #[Test]
    public function sin_prefijo_usa_el_pais_de_la_reserva(): void
    {
        // Clémence lo habría tecleado así, «como en casa», con la reserva marcada en Francia.
        self::assertSame('33652307493', (new PhoneSanitizer())->validoONulo('06 52 30 74 93', 'FR'));
        self::assertSame('51984123456', (new PhoneSanitizer())->validoONulo('984 123 456', 'PE'));
    }

    #[Test]
    public function un_numero_incompleto_o_basura_no_se_acepta(): void
    {
        $telefonos = new PhoneSanitizer();

        self::assertNull($telefonos->validoONulo('940418', 'PE'), 'Un fijo incompleto no es un WhatsApp.');
        self::assertNull($telefonos->validoONulo('hola', 'PE'));
        self::assertNull($telefonos->validoONulo('', 'PE'));
        self::assertNull($telefonos->validoONulo('06 52 30 74 93', null), 'Sin prefijo y sin país no se adivina.');
    }
}
