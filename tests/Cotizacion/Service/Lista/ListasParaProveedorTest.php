<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Lista;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Cotizacion\Service\Lista\ListaParaCultura;
use App\Cotizacion\Service\Lista\ListaParaPeruRail;
use App\Cotizacion\Service\Lista\RellenadorDePlantillaXlsx;
use App\Entity\Maestro\MaestroPais;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las dos listas de punta a punta, sobre las plantillas reales, con los pasajeros de EG95UF.
 */
final class ListasParaProveedorTest extends TestCase
{
    private const string PLANTILLAS = __DIR__ . '/../../../../public/templates/';

    private static function pasajero(string $nombre, string $apellido, string $nacimiento, SexoEnum $sexo, MaestroPais $pais, DocumentoTipoEnum $tipo, string $numero): CotizacionFilepasajero
    {
        $p = (new CotizacionFilepasajero())
            ->setNombre($nombre)->setApellido($apellido)->setSexo($sexo)->setPais($pais)
            ->setFechanacimiento(new DateTimeImmutable($nacimiento));
        $p->addIdentificacion((new CotizacionPasajeroIdentificacion())->setTipo($tipo)->setNumero($numero));

        return $p;
    }

    /** @return list<CotizacionFilepasajero> */
    private static function grupo(): array
    {
        $pe = (new MaestroPais('PE', 'Perú'))->setCodigoMc(184)->setCodigoPeruRail(230);
        $es = (new MaestroPais('ES', 'España'))->setCodigoMc(64)->setCodigoPeruRail(176);

        return [
            self::pasajero('Esperanza', 'de la Cruz Cruz', '1963-10-29', SexoEnum::FEMENINO, $pe, DocumentoTipoEnum::DNI, '08606559'),
            self::pasajero('Gerardo', 'Santos de Blas', '1961-08-13', SexoEnum::MASCULINO, $es, DocumentoTipoEnum::PASAPORTE, 'PAQ024930'),
            self::pasajero('Bebé', 'Valverde', (new DateTimeImmutable('-1 year'))->format('Y-m-d'), SexoEnum::MASCULINO, $pe, DocumentoTipoEnum::DNI, '99999999'),
        ];
    }

    private static function hoja(string $binario, string $nombre): Worksheet
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 't') . '.xlsx';
        file_put_contents($temporal, $binario);
        $hoja = IOFactory::load($temporal)->getSheetByName($nombre);
        unlink($temporal);
        self::assertNotNull($hoja);

        return $hoja;
    }

    private static function file(): CotizacionFile
    {
        return (new CotizacionFile())->setLocalizador('EG95UF');
    }

    #[Test]
    public function cultura_pone_los_codigos_que_daria_su_buscarv(): void
    {
        $lista = new ListaParaCultura(new RellenadorDePlantillaXlsx(), self::PLANTILLAS . 'tuboletocultura.xlsx');
        $archivo = $lista->generar(self::file(), self::grupo());
        $h = self::hoja($archivo->contenido, 'Hoja1');

        // Esperanza: peruana, adulta → General promocional (4), DNI (1), Perú (184).
        self::assertSame(['Peruano', 'Perú', 'General promocional', 'DNI', '08606559', '29-10-1963', 'Esperanza', 'de la Cruz', 'Cruz', 'EG95UF', 'Femenino'],
            array_map(static fn (string $c): mixed => $h->getCell($c . '4')->getValue(), ['C', 'E', 'G', 'I', 'K', 'L', 'M', 'N', 'O', 'P', 'Q']));
        self::assertSame([2, 184, 4, 1], array_map(static fn (string $c): int => (int) $h->getCell($c . '4')->getOldCalculatedValue(), ['D', 'F', 'H', 'J']));

        // Gerardo: español → Extranjero (1), General (1), PAS (3), España (64), «Santos» + «de Blas».
        self::assertSame(['Extranjero', 'España', 'General', 'PAS', 'Santos', 'de Blas'],
            array_map(static fn (string $c): mixed => $h->getCell($c . '5')->getValue(), ['C', 'E', 'G', 'I', 'N', 'O']));
        self::assertSame([1, 64, 1, 3], array_map(static fn (string $c): int => (int) $h->getCell($c . '5')->getOldCalculatedValue(), ['D', 'F', 'H', 'J']));

        // El bebé no lleva entrada, y se dice.
        self::assertNull($h->getCell('C6')->getValue());
        self::assertCount(1, $archivo->avisos);
        self::assertStringContainsString('menor de 3 años', $archivo->avisos[0]);
        self::assertSame('MC_EG95UF.xlsx', $archivo->nombre);
    }

    #[Test]
    public function la_edad_es_la_del_viaje_y_no_la_de_hoy(): void
    {
        // Tiene 11 hoy y cumple 12 antes de viajar: en el tren va de adulto.
        $nino = self::pasajero('Leo', 'Ríos', (new DateTimeImmutable('-12 years +1 month'))->format('Y-m-d'),
            SexoEnum::MASCULINO, (new MaestroPais('PE', 'Perú'))->setCodigoPeruRail(230), DocumentoTipoEnum::DNI, '77777777');
        self::assertSame(11, $nino->getEdad());

        $file = self::file();
        $vendida = (new Cotizacion())->setEstado(CotizacionEstadoEnum::CONFIRMADO);
        $vendida->addCotservicio((new CotizacionCotservicio())->setFechaInicioAbsoluta(new DateTimeImmutable('+3 months')));
        $file->addCotizacion($vendida);

        $lista = new ListaParaPeruRail(new RellenadorDePlantillaXlsx(), self::PLANTILLAS . 'perurail.xlsx');
        $h = self::hoja($lista->generar($file, [$nino])->contenido, 'PASAJEROS');

        self::assertSame(1, $h->getCell('A2')->getValue());
    }

    #[Test]
    public function perurail_como_la_del_panel_viejo(): void
    {
        $lista = new ListaParaPeruRail(new RellenadorDePlantillaXlsx(), self::PLANTILLAS . 'perurail.xlsx');
        $archivo = $lista->generar(self::file(), self::grupo());
        $h = self::hoja($archivo->contenido, 'PASAJEROS');

        self::assertSame([1, 'F', 'DNI', '08606559', 'Esperanza', 'de la Cruz Cruz', '29/10/1963', 230],
            array_map(static fn (string $c): mixed => $h->getCell($c . '2')->getValue(), ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']));
        self::assertSame([1, 'M', 'PAS', 'PAQ024930', 176],
            array_map(static fn (string $c): mixed => $h->getCell($c . '3')->getValue(), ['A', 'B', 'C', 'D', 'H']));
        // El bebé SÍ va en el tren: tipo 2, niño.
        self::assertSame(2, $h->getCell('A4')->getValue());
        self::assertSame([], $archivo->avisos);
    }

    #[Test]
    public function mas_de_diez_en_cultura_son_varias_partes_en_un_zip(): void
    {
        $pe = (new MaestroPais('PE', 'Perú'))->setCodigoMc(184);
        $grupo = array_map(static fn (int $i): CotizacionFilepasajero => self::pasajero("P$i", 'Quispe Mamani', '1990-01-01', SexoEnum::MASCULINO, $pe, DocumentoTipoEnum::DNI, sprintf('%08d', $i)), range(1, 12));

        $archivo = (new ListaParaCultura(new RellenadorDePlantillaXlsx(), self::PLANTILLAS . 'tuboletocultura.xlsx'))->generar(self::file(), $grupo);

        self::assertSame('application/zip', $archivo->tipoMime);
        self::assertSame('MC_EG95UF.zip', $archivo->nombre);
    }
}
