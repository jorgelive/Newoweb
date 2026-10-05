<?php

declare(strict_types=1);

namespace App\Tests\Travel\Enum;

use App\Travel\Enum\TarifaCalculoEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los tres predicados que sustituyen al booleano `esGrupal`.
 *
 * ⚠️ **Lo que fija este test es que las dos preguntas sean DISTINTAS.** El booleano al que
 * sustituye las respondía a la vez —«× 1» y «/ numPax» iban juntas— y por eso parecía bastar.
 * `OPERATIVA` las separa, y si alguien las volviera a juntar el síntoma sería una cifra por
 * persona baja: plausible, y revisada por nadie.
 */
final class TarifaCalculoTest extends TestCase
{
    #[Test]
    public function individual_multiplica_y_no_reparte(): void
    {
        $m = TarifaCalculoEnum::INDIVIDUAL;

        self::assertTrue($m->multiplicaPorCantidad());
        self::assertFalse($m->seProrratea(), 'El monto YA es por pax: dividirlo otra vez lo encogería.');
        self::assertTrue($m->visibleParaCliente());
    }

    #[Test]
    public function grupal_no_multiplica_y_reparte(): void
    {
        $m = TarifaCalculoEnum::GRUPAL;

        self::assertFalse($m->multiplicaPorCantidad(), 'Precio cerrado: multiplicar por pax lo dobla.');
        self::assertTrue($m->seProrratea());
        self::assertTrue($m->visibleParaCliente());
    }

    #[Test]
    public function operativa_multiplica_Y_reparte_y_no_se_ve(): void
    {
        // 🔑 La combinación que el booleano no podía expresar: cinco vuelos liberados son
        // `cantidad = 5` —se multiplica— y su costo se reparte entre todo el grupo sin salir
        // como línea.
        $m = TarifaCalculoEnum::OPERATIVA;

        self::assertTrue($m->multiplicaPorCantidad());
        self::assertTrue($m->seProrratea());
        self::assertFalse($m->visibleParaCliente());
    }

    #[Test]
    public function las_dos_preguntas_no_son_la_misma(): void
    {
        // Si alguien colapsara los dos predicados en uno, este test se cae: existe una modalidad
        // en la que no coinciden, y es justo la que motivó el cambio.
        $discrepan = array_filter(
            TarifaCalculoEnum::cases(),
            static fn (TarifaCalculoEnum $m): bool => $m->multiplicaPorCantidad() === $m->seProrratea()
        );

        self::assertSame([TarifaCalculoEnum::OPERATIVA], array_values($discrepan));
    }

    #[Test]
    public function solo_la_operativa_se_esconde(): void
    {
        $ocultas = array_filter(
            TarifaCalculoEnum::cases(),
            static fn (TarifaCalculoEnum $m): bool => !$m->visibleParaCliente()
        );

        self::assertSame([TarifaCalculoEnum::OPERATIVA], array_values($ocultas));
    }

    #[Test]
    public function las_tres_tienen_etiqueta(): void
    {
        // Espejo de ETIQUETAS_MODALIDAD en dominio/cotizacion/modalidadTarifa.ts
        self::assertSame('Individual', TarifaCalculoEnum::INDIVIDUAL->etiqueta());
        self::assertSame('Grupal', TarifaCalculoEnum::GRUPAL->etiqueta());
        self::assertSame('Operativa', TarifaCalculoEnum::OPERATIVA->etiqueta());
    }
}
