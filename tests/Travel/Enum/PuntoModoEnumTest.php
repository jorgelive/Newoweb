<?php

declare(strict_types=1);

namespace App\Tests\Travel\Enum;

use App\Travel\Enum\PuntoModoEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los tres estados de un extremo geográfico, y por qué son tres.
 */
final class PuntoModoEnumTest extends TestCase
{
    #[Test]
    public function solo_el_punto_fijo_exige_elegir_un_punto(): void
    {
        self::assertTrue(PuntoModoEnum::FIJO->exigePunto());
        self::assertFalse(PuntoModoEnum::ALOJAMIENTO->exigePunto());
        self::assertFalse(PuntoModoEnum::SIN_DEFINIR->exigePunto());
    }

    #[Test]
    public function sin_definir_es_el_unico_que_no_declara_nada(): void
    {
        // «El hotel del pasajero» SÍ es una declaración, aunque no traiga dirección: dice que
        // el dato lo pone la reserva. Confundirlo con un hueco es lo que haría que el informe
        // de pendientes fuese inservible.
        self::assertFalse(PuntoModoEnum::SIN_DEFINIR->esDeclarado());
        self::assertTrue(PuntoModoEnum::ALOJAMIENTO->esDeclarado());
        self::assertTrue(PuntoModoEnum::FIJO->esDeclarado());
    }

    #[Test]
    public function el_valor_por_defecto_de_la_columna_coincide_con_el_del_enum(): void
    {
        // La columna nace en 'sin_definir'. Si alguien renombra el caso y no la migración, los
        // segmentos existentes quedan con un valor que el enum no reconoce y Doctrine revienta
        // al hidratarlos — lejos de aquí y sin pista de por qué.
        self::assertSame('sin_definir', PuntoModoEnum::SIN_DEFINIR->value);
    }
}
