<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service\Lista;

use App\Cotizacion\Service\Lista\Celda;
use App\Cotizacion\Service\Lista\RellenadorDePlantillaXlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * El rellenador sobre la plantilla REAL del Ministerio de Cultura.
 *
 * Lo que se prueba es lo que lo justifica: que el libro sale como si alguien lo hubiera tecleado en
 * Excel —todas las partes intactas salvo la hoja, los textos y la fecha de modificación—, y que las
 * columnas de código conservan su fórmula con el resultado puesto.
 */
final class RellenadorDePlantillaXlsxTest extends TestCase
{
    private const string PLANTILLA = __DIR__ . '/../../../../public/templates/tuboletocultura.xlsx';

    private function rellenado(): string
    {
        return (new RellenadorDePlantillaXlsx())->rellenar(self::PLANTILLA, 'xl/worksheets/sheet1.xml', [
            'C4' => Celda::texto('Peruano'),
            'D4' => Celda::calculada(2),
            'E4' => Celda::texto('Perú'),
            'F4' => Celda::calculada(184),
            'K4' => Celda::texto('08606559'),
            'M4' => Celda::texto('Esperanza'),
            'N4' => Celda::texto('de la Cruz'),
        ]);
    }

    /** @return array<string, string> */
    private static function partes(string $binario): array
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 't');
        file_put_contents($temporal, $binario);
        $zip = new ZipArchive();
        $zip->open($temporal);
        $partes = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $nombre = (string) $zip->getNameIndex($i);
            $partes[$nombre] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($temporal);

        return $partes;
    }

    #[Test]
    public function todo_lo_que_no_son_datos_sale_identico_y_en_el_mismo_orden(): void
    {
        $antes = self::partes((string) file_get_contents(self::PLANTILLA));
        $despues = self::partes($this->rellenado());

        self::assertSame(array_keys($antes), array_keys($despues), 'Las mismas partes, en el mismo orden.');

        $tocadas = ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml', 'docProps/core.xml'];
        foreach ($antes as $nombre => $contenido) {
            if (!in_array($nombre, $tocadas, true)) {
                self::assertSame($contenido, $despues[$nombre], "«{$nombre}» no debe cambiar: Power Query, tabla, estilos, validaciones…");
            }
        }
    }

    #[Test]
    public function la_formula_se_queda_y_lleva_su_resultado(): void
    {
        $hoja = self::partes($this->rellenado())['xl/worksheets/sheet1.xml'];

        self::assertMatchesRegularExpression('#<c r="F4"[^>]*><f>IFERROR\(VLOOKUP\(Tabla1\[\[\#This Row\],\[País\]\][^<]*</f><v>184</v></c>#u', $hoja);
        // Un resultado numérico no lleva `t="str"`, como lo guardaría Excel.
        self::assertDoesNotMatchRegularExpression('#<c r="F4"[^>]*t="str"#', $hoja);
    }

    #[Test]
    public function los_textos_se_comparten_y_se_reutilizan_como_en_excel(): void
    {
        $partes = self::partes($this->rellenado());
        $textos = $partes['xl/sharedStrings.xml'];
        $antes = self::partes((string) file_get_contents(self::PLANTILLA))['xl/sharedStrings.xml'];

        // «Perú» ya estaba en la tabla (la hoja oculta de países): no se repite.
        self::assertSame(substr_count($antes, '<t>Perú</t>'), substr_count($textos, '<t>Perú</t>'));
        self::assertStringContainsString('<t>08606559</t>', $textos);
    }

    #[Test]
    public function el_libro_se_lee_con_sus_valores(): void
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 't') . '.xlsx';
        file_put_contents($temporal, $this->rellenado());
        $hoja = IOFactory::load($temporal)->getSheetByName('Hoja1');
        unlink($temporal);

        self::assertNotNull($hoja);
        self::assertSame('Peruano', $hoja->getCell('C4')->getValue());
        self::assertSame('08606559', $hoja->getCell('K4')->getValue(), 'Con el cero delante: es texto.');
        self::assertSame(184, (int) $hoja->getCell('F4')->getOldCalculatedValue());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unCaracterDeControlNoRompeElXml(): void
    {
        $xml = '<t>' . \App\Cotizacion\Service\Lista\TextosCompartidos::paraXml("Ana\x07 Pérez & <Ñ>\x0B") . '</t>';

        self::assertNotFalse(simplexml_load_string($xml), 'tiene que ser XML válido');
        self::assertSame('Ana Pérez & <Ñ>', (string) simplexml_load_string($xml));
    }
}
