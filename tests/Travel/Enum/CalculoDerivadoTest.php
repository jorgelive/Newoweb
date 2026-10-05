<?php

declare(strict_types=1);

namespace App\Tests\Travel\Enum;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaCalculoEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fase 5 del plan: **el cálculo MANDA y el booleano es la copia**.
 *
 * La dirección se invirtió —en la fase 2 era al revés— y lo que se vigila es lo mismo de siempre:
 * que no puedan decir cosas distintas. Lo que cambia es quién gana si lo intentaran.
 *
 * ⚠️ El caso que más fácil se rompe es `setEsGrupal(false)` sobre una operativa: también es «no
 * grupal», así que una traducción ingenua la convertiría en individual —multiplicaría igual pero
 * dejaría de repartirse, y volvería a verse en el itinerario del cliente— sin que nadie lo pidiera.
 */
final class CalculoDerivadoTest extends TestCase
{
    #[Test]
    public function el_calculo_manda_y_el_booleano_lo_sigue(): void
    {
        $grupal = (new TravelTarifa())->setCalculo(TarifaCalculoEnum::GRUPAL);
        $operativa = (new TravelTarifa())->setCalculo(TarifaCalculoEnum::OPERATIVA);

        self::assertTrue($grupal->isCostoPorGrupo(), 'La copia tiene que seguir al cálculo.');
        // Una operativa NO es grupal: multiplica por cantidad, y el booleano lo dice.
        self::assertFalse($operativa->isCostoPorGrupo());
    }

    #[Test]
    public function el_camino_viejo_traduce_en_vez_de_romper(): void
    {
        // El panel y los cargadores siguen llamando a setCostoPorGrupo().
        self::assertSame(TarifaCalculoEnum::GRUPAL, (new TravelTarifa())->setCostoPorGrupo(true)->getCalculo());
        self::assertSame(TarifaCalculoEnum::INDIVIDUAL, (new TravelTarifa())->setCostoPorGrupo(false)->getCalculo());
    }

    #[Test]
    public function un_false_del_camino_viejo_NO_pisa_una_operativa(): void
    {
        // 🔑 Una operativa también es «no grupal». Si `setCostoPorGrupo(false)` la tradujera a
        // individual, dejaría de repartirse y volvería a verse en el itinerario del cliente — por
        // un campo que el formulario manda siempre, lo toque alguien o no.
        $t = (new TravelTarifa())->setCalculo(TarifaCalculoEnum::OPERATIVA)->setCostoPorGrupo(false);

        self::assertSame(TarifaCalculoEnum::OPERATIVA, $t->getCalculo());
    }

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
        self::assertSame('grupal', (new CotizacionCottarifa())->setEsGrupal(true)->getCalculoSnapshot());
        self::assertSame('individual', (new CotizacionCottarifa())->setEsGrupal(false)->getCalculoSnapshot());
        self::assertSame('operativa', (new CotizacionCottarifa())->setCalculo('operativa')->getCalculoSnapshot());

        $op = (new CotizacionCottarifa())->setCalculo('operativa')->setEsGrupal(false);
        self::assertSame('operativa', $op->getCalculoSnapshot(), 'Un false no puede pisar la operativa.');
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
        self::assertSame('individual', (new CotizacionCottarifa())->setCalculo('marciano')->getCalculoSnapshot());
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
