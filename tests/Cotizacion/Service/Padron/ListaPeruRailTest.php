<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Cotizacion\Service\Padron\ListaPeruRail;
use App\Entity\Maestro\MaestroPais;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * La lista de PeruRail rellena SU plantilla con los mismos códigos que mandaba Oweb.
 */
final class ListaPeruRailTest extends TestCase
{
    private function proyecto(): string
    {
        return dirname(__DIR__, 4);
    }

    private function pais(string $id, string $nombre, ?int $codigo): MaestroPais
    {
        $pais = new MaestroPais($id, $nombre);
        $pais->setCodigoPeruRail($codigo);

        return $pais;
    }

    private function pasajero(
        CotizacionFile $file,
        string $nombre,
        string $apellido,
        ?MaestroPais $pais,
        string $nacimiento,
        ?DocumentoTipoEnum $tipoDoc = null,
        ?string $numero = null,
    ): CotizacionFilepasajero {
        $p = (new CotizacionFilepasajero())
            ->setNombre($nombre)
            ->setApellido($apellido)
            ->setPais($pais)
            ->setSexo(SexoEnum::FEMENINO)
            ->setFechanacimiento(new \DateTimeImmutable($nacimiento))
            ->setTipo(PasajeroTipoEnum::PARTICIPANTE);

        if ($tipoDoc !== null) {
            $doc = (new CotizacionPasajeroIdentificacion())->setTipo($tipoDoc)->setNumero($numero);
            $p->addIdentificacion($doc);
        }

        $file->addFilepasajero($p);

        return $p;
    }

    private function hoja(string $contenido): Worksheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pr_test_');
        self::assertIsString($ruta);
        file_put_contents($ruta, $contenido);
        $libro = IOFactory::load($ruta);
        unlink($ruta);

        $hoja = $libro->getSheetByName('PASAJEROS');
        self::assertNotNull($hoja);

        return $hoja;
    }

    public function testCodigosDeDocumentoSalenDeLaTablaDeLaPlantilla(): void
    {
        self::assertSame('DNI', DocumentoTipoEnum::DNI->getCodigoPeruRail());
        self::assertSame('CEX', DocumentoTipoEnum::CE->getCodigoPeruRail());
        self::assertSame('PAS', DocumentoTipoEnum::PASAPORTE->getCodigoPeruRail());
        self::assertSame('ID', DocumentoTipoEnum::CI->getCodigoPeruRail());
        self::assertNull(DocumentoTipoEnum::RUC->getCodigoPeruRail());
    }

    public function testRellenaLaPlantillaDesdeLaFila2(): void
    {
        $file = (new CotizacionFile())->setNombreGrupo('Promo Santa Rosa');
        $peru = $this->pais('PE', 'Perú', 1);
        $usa = $this->pais('US', 'Estados Unidos', 207);

        // Peruana con DNI y pasaporte: va el DNI, con su cero inicial.
        $ana = $this->pasajero($file, 'Ana', 'Quispe', $peru, '2010-05-03', DocumentoTipoEnum::PASAPORTE, '123456');
        $ana->addIdentificacion((new CotizacionPasajeroIdentificacion())->setTipo(DocumentoTipoEnum::DNI)->setNumero('01234567'));
        // Extranjera: pasaporte.
        $this->pasajero($file, 'Beth', 'Smith', $usa, '1980-01-01', DocumentoTipoEnum::PASAPORTE, 'X 12-34');

        $resultado = (new ListaPeruRail($this->proyecto()))
            ->generar($file, null, new \DateTimeImmutable('2026-12-01'));

        self::assertFalse($resultado->esZip);
        self::assertSame('PERURAIL_Promo-Santa-Rosa.xlsx', $resultado->nombreArchivo);
        self::assertSame([], $resultado->incompletos);

        $hoja = $this->hoja($resultado->contenido);
        self::assertSame('TIPO PASAJERO', $hoja->getCell('A1')->getValue());

        // Ordenado por apellido: Quispe, Smith.
        self::assertSame(1, (int) $hoja->getCell('A2')->getValue(), 'Ana tiene 16 el día del tren');
        self::assertSame('F', $hoja->getCell('B2')->getValue());
        self::assertSame('DNI', $hoja->getCell('C2')->getValue());
        self::assertSame('01234567', $hoja->getCell('D2')->getValue());
        self::assertSame('Ana', $hoja->getCell('E2')->getValue());
        self::assertSame('Quispe', $hoja->getCell('F2')->getValue());
        self::assertSame('03/05/2010', $hoja->getCell('G2')->getValue());
        self::assertSame(1, (int) $hoja->getCell('H2')->getValue());

        self::assertSame('PAS', $hoja->getCell('C3')->getValue());
        self::assertSame('X1234', $hoja->getCell('D3')->getValue());
        self::assertSame(207, (int) $hoja->getCell('H3')->getValue());
    }

    /** Adulto/niño se decide el día del tren: once años hoy, doce al subir. */
    public function testLaEdadEsLaDelDiaDelTren(): void
    {
        $file = (new CotizacionFile())->setNombreGrupo('G');
        $nino = $this->pasajero($file, 'Leo', 'Paz', $this->pais('PE', 'Perú', 1), '2015-06-10', DocumentoTipoEnum::DNI, '7');

        self::assertSame(2, $nino->getTipopaxperurail(new \DateTimeImmutable('2027-06-09')));
        self::assertSame(1, $nino->getTipopaxperurail(new \DateTimeImmutable('2027-06-10')));
    }

    /** Un hueco no tumba la descarga (Oweb daba un 500): queda vacío, en ámbar, y se avisa. */
    public function testLosHuecosSeAvisanEnVezDeRomper(): void
    {
        $file = (new CotizacionFile())->setNombreGrupo('G');
        $this->pasajero($file, 'Sin', 'Documento', $this->pais('BO', 'Bolivia', null), '1990-01-01');

        $resultado = (new ListaPeruRail($this->proyecto()))->generar($file);

        self::assertCount(1, $resultado->incompletos);
        self::assertStringContainsString('tipo de documento', $resultado->incompletos[0]);
        self::assertStringContainsString('código PeruRail del país Bolivia', $resultado->incompletos[0]);
    }

    public function testLosNoParticipaNoSalenEnLaListaCompleta(): void
    {
        $file = (new CotizacionFile())->setNombreGrupo('G');
        $peru = $this->pais('PE', 'Perú', 1);
        $this->pasajero($file, 'Va', 'Uno', $peru, '1990-01-01', DocumentoTipoEnum::DNI, '1');
        $this->pasajero($file, 'NoVa', 'Dos', $peru, '1990-01-01', DocumentoTipoEnum::DNI, '2')
            ->setTipo(PasajeroTipoEnum::NO_PARTICIPA);

        self::assertSame(1, (new ListaPeruRail($this->proyecto()))->generar($file)->personas);
    }

    /** Las listas desplegables de la plantilla llegan a la fila 101: de 100 en 100, en un ZIP. */
    public function testMasDeCienPersonasBajanEnUnZip(): void
    {
        $file = (new CotizacionFile())->setNombreGrupo('G');
        $peru = $this->pais('PE', 'Perú', 1);
        for ($i = 0; $i < 101; $i++) {
            $this->pasajero($file, 'N' . $i, sprintf('A%03d', $i), $peru, '1990-01-01', DocumentoTipoEnum::DNI, (string) $i);
        }

        $resultado = (new ListaPeruRail($this->proyecto()))->generar($file);

        self::assertTrue($resultado->esZip);
        self::assertSame('PERURAIL_G.zip', $resultado->nombreArchivo);

        $ruta = tempnam(sys_get_temp_dir(), 'pr_zip_');
        self::assertIsString($ruta);
        file_put_contents($ruta, $resultado->contenido);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($ruta) === true);
        self::assertSame(2, $zip->numFiles);
        self::assertSame('PERURAIL_G_Parte_1.xlsx', $zip->getNameIndex(0));
        $zip->close();
        unlink($ruta);
    }
}
