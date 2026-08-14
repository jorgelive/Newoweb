<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Una cotización no se desengancha de su expediente.
 *
 * El editor guarda con un PUT del árbol entero: si el payload llega sin `file`, la
 * cotización se quedaba huérfana en silencio —la columna es nullable— y el expediente
 * perdía su versión sin que nada lo dijera. Sólo se notó porque al confirmar, ese file
 * se copia a cada OperacionServicio, donde la columna es NOT NULL, y el guardado entero
 * moría con un «Column 'file_id' cannot be null».
 *
 * Ver Cotizacion::setFile() y BibliaSnapshotService::resolverFile().
 */
final class CotizacionFileGuardTest extends TestCase
{
    #[Test]
    public function el_null_del_payload_no_desengancha_el_expediente(): void
    {
        $expediente = new CotizacionFile();
        $cotizacion = (new Cotizacion())->setFile($expediente);

        $cotizacion->setFile(null);

        self::assertSame($expediente, $cotizacion->getFile());
    }

    /**
     * Mover una cotización a OTRO expediente sí es una intención real, y se permite.
     * El guard es contra el null que nadie pidió, no contra reasignar.
     */
    #[Test]
    public function reasignar_a_otro_expediente_sigue_permitido(): void
    {
        $original = new CotizacionFile();
        $destino  = new CotizacionFile();

        $cotizacion = (new Cotizacion())->setFile($original);
        $cotizacion->setFile($destino);

        self::assertSame($destino, $cotizacion->getFile());
    }

    /**
     * Las cotizaciones de catálogo nacen y viven sin file: el guard no puede inventarles uno
     * ni estorbar cuando el null es el estado legítimo.
     */
    #[Test]
    public function una_cotizacion_sin_expediente_admite_el_null(): void
    {
        $cotizacion = new Cotizacion();

        $cotizacion->setFile(null);

        self::assertNull($cotizacion->getFile());
    }
}
