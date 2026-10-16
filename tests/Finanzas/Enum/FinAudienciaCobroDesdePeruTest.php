<?php

declare(strict_types=1);

namespace App\Tests\Finanzas\Enum;

use App\Finanzas\Enum\FinAudienciaCobro;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Quién paga desde Perú: lo preguntan la reserva de alojamiento y el expediente de cotización, y
 * tienen que contestar lo mismo. La parte que va contra la intuición es el prefijo 51: el saneador
 * de teléfonos lo antepone por defecto, así que un 51 no demuestra nada.
 */
#[CoversClass(FinAudienciaCobro::class)]
final class FinAudienciaCobroDesdePeruTest extends TestCase
{
    public function testElPaisManda(): void
    {
        self::assertTrue(FinAudienciaCobro::desdePeru('PE', '34600111222'));
        self::assertTrue(FinAudienciaCobro::desdePeru('pe', null));
        self::assertFalse(FinAudienciaCobro::desdePeru('US', '51958191965'));
    }

    public function testSinPaisUnTelefonoExtranjeroEsDeFuera(): void
    {
        self::assertFalse(FinAudienciaCobro::desdePeru(null, '+34 600 111 222'));
    }

    public function testSinPaisUnPrefijo51NoDemuestraQueSeaPeruano(): void
    {
        // Puede haberlo puesto el saneador: se le enseña todo y elige.
        self::assertNull(FinAudienciaCobro::desdePeru(null, '51958191965'));
        self::assertNull(FinAudienciaCobro::desdePeru('', '+51 958 191 965'));
    }

    public function testSinNadaNoSeSabe(): void
    {
        self::assertNull(FinAudienciaCobro::desdePeru(null, null));
        self::assertNull(FinAudienciaCobro::desdePeru('  ', ''));
    }

    public function testSinSaberloSeLeOfreceTodo(): void
    {
        foreach (FinAudienciaCobro::cases() as $audiencia) {
            self::assertTrue($audiencia->aplicaA(null));
        }
    }
}
