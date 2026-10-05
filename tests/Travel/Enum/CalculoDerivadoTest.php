<?php

declare(strict_types=1);

namespace App\Tests\Travel\Enum;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaCalculoEnum;
use App\Travel\Enum\TarifaRolEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fase 2 del plan: la columna nueva es una COPIA DERIVADA que no puede divergir.
 *
 * ⚠️ Lo que se vigila no es la traducción —eso es un `match` de tres líneas— sino que se rehaga
 * **desde los dos setters**, en cualquier orden. Si uno de ellos se olvidara, el campo nuevo se
 * quedaría diciendo lo anterior; y como en esta fase **nadie lo lee todavía**, el error viviría
 * tranquilo hasta la fase 3, donde ya sería un precio mal calculado.
 */
final class CalculoDerivadoTest extends TestCase
{
    #[Test]
    public function el_maestro_deriva_los_tres_casos(): void
    {
        $individual = (new TravelTarifa())->setCostoPorGrupo(false);
        $grupal = (new TravelTarifa())->setCostoPorGrupo(true);
        $operativa = (new TravelTarifa())->setRol(TarifaRolEnum::OPERATIVO);

        self::assertSame(TarifaCalculoEnum::INDIVIDUAL, $individual->getCalculo());
        self::assertSame(TarifaCalculoEnum::GRUPAL, $grupal->getCalculo());
        self::assertSame(TarifaCalculoEnum::OPERATIVA, $operativa->getCalculo());
    }

    #[Test]
    public function operativo_gana_sobre_grupal_en_cualquier_orden(): void
    {
        // Las 22 del catálogo son operativo + grupal. Da igual cuál se escriba antes: el resultado
        // tiene que ser el mismo, o el dato dependería del orden del formulario.
        $a = (new TravelTarifa())->setCostoPorGrupo(true)->setRol(TarifaRolEnum::OPERATIVO);
        $b = (new TravelTarifa())->setRol(TarifaRolEnum::OPERATIVO)->setCostoPorGrupo(true);

        self::assertSame(TarifaCalculoEnum::OPERATIVA, $a->getCalculo());
        self::assertSame(TarifaCalculoEnum::OPERATIVA, $b->getCalculo());
    }

    #[Test]
    public function quitar_el_rol_operativo_devuelve_el_calculo_al_booleano(): void
    {
        $t = (new TravelTarifa())->setCostoPorGrupo(true)->setRol(TarifaRolEnum::OPERATIVO);
        self::assertSame(TarifaCalculoEnum::OPERATIVA, $t->getCalculo());

        // La copia tiene que seguir al cambio, no quedarse con lo de antes.
        $t->setRol(TarifaRolEnum::ESTANDAR);
        self::assertSame(TarifaCalculoEnum::GRUPAL, $t->getCalculo());
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
    public function el_snapshot_deriva_igual_que_el_maestro(): void
    {
        $individual = (new CotizacionCottarifa())->setEsGrupal(false);
        $grupal = (new CotizacionCottarifa())->setEsGrupal(true);
        $operativa = (new CotizacionCottarifa())->setRolSnapshot('operativo');

        self::assertSame('individual', $individual->getCalculoSnapshot());
        self::assertSame('grupal', $grupal->getCalculoSnapshot());
        self::assertSame('operativa', $operativa->getCalculoSnapshot());
    }

    #[Test]
    public function el_snapshot_tambien_sigue_al_cambio(): void
    {
        $t = (new CotizacionCottarifa())->setEsGrupal(true)->setRolSnapshot('operativo');
        self::assertSame('operativa', $t->getCalculoSnapshot());

        $t->setRolSnapshot('estandar');
        self::assertSame('grupal', $t->getCalculoSnapshot());

        $t->setEsGrupal(false);
        self::assertSame('individual', $t->getCalculoSnapshot());
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
