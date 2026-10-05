<?php

declare(strict_types=1);

namespace App\Tests\Travel\Enum;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaCalculoEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El cálculo es ahora el ÚNICO campo: el booleano `esGrupal`/`costoPorGrupo` se borró en la fase 6b.
 *
 * Lo que queda por fijar es lo poco que hay: que el vocabulario sea el mismo a los dos lados —el
 * maestro guarda enum y el snapshot texto— y que un valor desconocido no se convierta en null.
 */
final class CalculoDerivadoTest extends TestCase
{
    #[Test]
    public function sin_haber_tocado_nada_tambien_responde(): void
    {
        // Una fila escrita por SQL antes del relleno no tiene la columna. Devolver null obligaría
        // a cada consumidor a decidir qué hacer, que es como se reparten las reglas por el código.
        self::assertSame(TarifaCalculoEnum::INDIVIDUAL, (new TravelTarifa())->getCalculo());
        self::assertSame('individual', (new CotizacionCottarifa())->getCalculoSnapshot());
    }

    #[Test]
    public function el_snapshot_se_comporta_igual(): void
    {
        self::assertSame('grupal', (new CotizacionCottarifa())->setCalculoSnapshot('grupal')->getCalculoSnapshot());
        self::assertSame('individual', (new CotizacionCottarifa())->setCalculoSnapshot('individual')->getCalculoSnapshot());
        self::assertSame('operativa', (new CotizacionCottarifa())->setCalculoSnapshot('operativa')->getCalculoSnapshot());
    }

    #[Test]
    public function un_payload_viejo_con_rol_operativo_se_traduce(): void
    {
        // ⚠️ `operativo` dejó de ser un rol. Un cliente desactualizado que todavía lo mande no es
        // un error: se traduce. Rechazarlo rompería el guardado entero por un campo.
        $t = (new CotizacionCottarifa())->setRolSnapshot('operativo');

        self::assertSame('operativa', $t->getCalculoSnapshot());
        self::assertSame('estandar', $t->getRolSnapshot(), 'El rol queda en el valor que sí existe.');
    }

    #[Test]
    public function un_calculo_desconocido_cae_a_individual(): void
    {
        self::assertSame('individual', (new CotizacionCottarifa())->setCalculoSnapshot('marciano')->getCalculoSnapshot());
    }

    #[Test]
    public function los_dos_lados_usan_el_mismo_vocabulario(): void
    {
        // El snapshot guarda texto y el maestro un enum: si los valores se separaran, un snapshot
        // dejaría de poder leerse como el enum del que salió.
        $delEnum = array_map(static fn (TarifaCalculoEnum $c): string => $c->value, TarifaCalculoEnum::cases());

        self::assertSame(['individual', 'grupal', 'operativa'], $delEnum);
    }
}
