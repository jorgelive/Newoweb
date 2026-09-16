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

    /**
     * 🔥 **Una columna de observaciones POR DOCUMENTO, no una global.**
     *
     * Era una sola celda con todo concatenado, y con eso no se puede filtrar «enséñame a quién le
     * falla el E-Ticket» — que es la única pregunta con la que se abre esta hoja.
     */
    public function testCadaDocumentoTieneSuColumnaDeObservaciones(): void
    {
        $file = new CotizacionFile();
        $file->setDocumentosPedidos(['pasaporte', 'eticket']);

        $cabeceras = $this->cabecerasDe($file);

        self::assertContains('Observaciones '.ArchivoTipoEnum::ETICKET->getLabel(), $cabeceras);
        self::assertContains('Observaciones '.ArchivoTipoEnum::PASAPORTE->getLabel(), $cabeceras);
        self::assertNotContains('Observaciones', $cabeceras, 'La columna global tenía que desaparecer.');
    }

    /**
     * ⚠️ **La aritmética de columnas, que es lo que se rompe en silencio.**
     *
     * «Archivos» y «Qué falta» se colocaban con `$ultima - 2` y `$ultima - 1`, y eso dejó de
     * significar nada en cuanto hubo DOS bloques que crecen con lo que pide el expediente. Un
     * desfase de una columna no da error: escribe el total encima de una observación.
     */
    public function testArchivosYQueFaltaCaenJustoAntesDeLasObservaciones(): void
    {
        foreach ([['pasaporte'], ['pasaporte', 'eticket'], ['pasaporte', 'dni_anverso', 'dni_reverso', 'eticket']] as $pedidos) {
            $file = new CotizacionFile();
            $file->setDocumentosPedidos($pedidos);

            $cabeceras = $this->cabecerasDe($file);
            $reporte = new ReporteDeDocumentos();
            $inicio = (new \ReflectionMethod($reporte, 'dondeEmpiezanLasObservaciones'))
                ->invoke($reporte, count($pedidos));

            // Las cabeceras se cuentan desde 1, como las celdas de PhpSpreadsheet.
            self::assertSame(ReporteDeDocumentos::COL_ARCHIVOS, $cabeceras[$inicio - 3], implode(',', $pedidos));
            self::assertSame(ReporteDeDocumentos::COL_FALTA, $cabeceras[$inicio - 2], implode(',', $pedidos));
            self::assertStringStartsWith('Observaciones ', $cabeceras[$inicio - 1], implode(',', $pedidos));
            self::assertCount($inicio - 1 + count($pedidos), $cabeceras);
        }
    }
}
