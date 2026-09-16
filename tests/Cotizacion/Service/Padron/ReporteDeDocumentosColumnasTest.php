<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Service\Padron\ReporteDeDocumentos;
use PHPUnit\Framework\TestCase;

/**
 * Las columnas de estado de la hoja de control salen de lo que el expediente PIDE.
 *
 * ⚠️ **Eran tres fijas y la hoja mentía.** Un expediente que exige el E-Ticket migratorio no tenía
 * columna para él, así que quien no lo hubiera mandado salía con «Completo» — en la única columna
 * que se lee, que es la que contesta «¿a quién le escribo hoy?». En el expediente real eso eran 39
 * personas dadas por completas.
 */
final class ReporteDeDocumentosColumnasTest extends TestCase
{
    /** @return list<string> */
    private function cabecerasDe(CotizacionFile $file): array
    {
        $reporte = new ReporteDeDocumentos();

        $escaneos = (new \ReflectionMethod($reporte, 'escaneosPedidos'))->invoke($reporte, $file);

        /** @var list<string> $cabeceras */
        $cabeceras = (new \ReflectionMethod($reporte, 'cabeceras'))->invoke($reporte, $escaneos);

        return $cabeceras;
    }

    public function testElEticketTieneColumnaCuandoElExpedienteLoPide(): void
    {
        $file = new CotizacionFile();
        $file->setDocumentosPedidos(['pasaporte', 'dni_anverso', 'dni_reverso', 'eticket']);

        self::assertContains(ArchivoTipoEnum::ETICKET->getLabel(), $this->cabecerasDe($file));
    }

    /** Y no sale si no se pide: una columna vacía para todos se lee como «nadie lo ha mandado». */
    public function testLoQueNoSePideNoTieneColumna(): void
    {
        $file = new CotizacionFile();
        $file->setDocumentosPedidos(['pasaporte']);

        $cabeceras = $this->cabecerasDe($file);

        self::assertContains(ArchivoTipoEnum::PASAPORTE->getLabel(), $cabeceras);
        self::assertNotContains(ArchivoTipoEnum::ETICKET->getLabel(), $cabeceras);
        self::assertNotContains(ArchivoTipoEnum::DNI_ANVERSO->getLabel(), $cabeceras);
    }

    /**
     * ⚠️ El orden lo decide el ENUM, no el orden en que el operador marcó las casillas: una hoja
     * cuyas columnas se mueven entre dos descargas no se puede comparar con la anterior.
     */
    public function testElOrdenNoDependeDeComoSeMarcaron(): void
    {
        $alReves = new CotizacionFile();
        $alReves->setDocumentosPedidos(['eticket', 'dni_reverso', 'pasaporte', 'dni_anverso']);

        $derecho = new CotizacionFile();
        $derecho->setDocumentosPedidos(['pasaporte', 'dni_anverso', 'dni_reverso', 'eticket']);

        self::assertSame($this->cabecerasDe($derecho), $this->cabecerasDe($alReves));
    }

    /** Las tres últimas no se mueven: la hoja se lee de derecha a izquierda para saber qué falta. */
    public function testLasTresUltimasSiguenSiendoLasDeSiempre(): void
    {
        $file = new CotizacionFile();
        $file->setDocumentosPedidos(['pasaporte', 'eticket']);

        self::assertSame(
            [ReporteDeDocumentos::COL_ARCHIVOS, ReporteDeDocumentos::COL_FALTA, 'Observaciones'],
            array_slice($this->cabecerasDe($file), -3),
        );
    }
}
