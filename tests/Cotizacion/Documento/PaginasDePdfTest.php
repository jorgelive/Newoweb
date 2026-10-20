<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\PaginasDePdf;
use Dompdf\Dompdf;
use Imagick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Girar y enseñar un PDF, con binarios y ficheros de verdad: lo que se prueba ES la llamada a qpdf y
 * a Ghostscript, y un doble no diría nada. Si en esta máquina faltan, se salta — no falla.
 *
 * Ver docs/Cotizaciones.md («PDF: girar y enseñar»).
 */
#[CoversClass(PaginasDePdf::class)]
final class PaginasDePdfTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        foreach (['qpdf', 'gs'] as $binario) {
            if ((new ExecutableFinder())->find($binario, null, ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']) === null) {
                self::markTestSkipped("Falta «{$binario}» en esta máquina.");
            }
        }

        $this->carpeta = sys_get_temp_dir() . '/paginas-pdf-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->carpeta)) {
            rmdir($this->carpeta);
        }
    }

    /** Un PDF A4 vertical con `$paginas` páginas. */
    private function pdf(int $paginas): string
    {
        $html = implode('<div style="page-break-before: always"></div>', array_map(
            static fn (int $n): string => "<h1>Página {$n}</h1>",
            range(1, $paginas)
        ));
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $ruta = $this->carpeta . '/doc.pdf';
        file_put_contents($ruta, (string) $dompdf->output());

        return $ruta;
    }

    /** @return array{0: int, 1: int} ancho y alto de la vista */
    private static function medidas(string $webp): array
    {
        $img = new Imagick($webp);

        return [$img->getImageWidth(), $img->getImageHeight()];
    }

    public function testLaVistaApilaLasPaginasYSeReutiliza(): void
    {
        $servicio = new PaginasDePdf();
        $una = $this->pdf(1);
        [$ancho, $altoUna] = self::medidas($servicio->vista($una));

        $dos = $this->pdf(2);
        unlink(PaginasDePdf::rutaDeVista($dos));
        $vista = $servicio->vista($dos);
        [, $altoDos] = self::medidas($vista);

        self::assertGreaterThan($ancho, $altoUna, 'un A4 vertical es más alto que ancho');
        self::assertGreaterThan(2 * $altoUna - 1, $altoDos, 'dos páginas, una debajo de otra (y el separador)');

        // Con el PDF sin cambios, la segunda petición no la rehace: se le deja una marca y sigue ahí.
        file_put_contents($vista, 'marca');
        touch($vista, (int) filemtime($dos) + 1);
        $servicio->vista($dos);
        self::assertSame('marca', file_get_contents($vista));
    }

    /** No más de 4 páginas: un PDF de 30 no se pinta entero. */
    public function testLaVistaNoPasaDeCuatroPaginas(): void
    {
        $servicio = new PaginasDePdf();
        [, $altoUna] = self::medidas($servicio->vista($this->pdf(1)));
        unlink(PaginasDePdf::rutaDeVista($this->carpeta . '/doc.pdf'));

        [, $altoSeis] = self::medidas($servicio->vista($this->pdf(6)));

        self::assertLessThan(5 * $altoUna, $altoSeis);
    }

    /**
     * Una página de 14 400 pt —el máximo del formato— a 110 ppp serían ~22 000 px de lado. Se pinta
     * a menos resolución: la vista sale, conserva su forma y no pasa por un PNG de gigabytes.
     */
    public function testUnaPaginaGiganteSePintaEncogida(): void
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<h1>Enorme</h1>');
        $dompdf->setPaper([0, 0, 14400, 14400]);
        $dompdf->render();
        $ruta = $this->carpeta . '/gigante.pdf';
        file_put_contents($ruta, (string) $dompdf->output());

        $inicio = microtime(true);
        [$ancho, $alto] = self::medidas((new PaginasDePdf())->vista($ruta));

        self::assertLessThanOrEqual(2000, max($ancho, $alto));
        self::assertLessThan(20, microtime(true) - $inicio);
    }

    /** Girar 90° deja la página apaisada, tira la vista vieja y no rasteriza: sigue siendo un PDF. */
    public function testGirarCambiaElGiroDeLaPaginaSinRasterizar(): void
    {
        $servicio = new PaginasDePdf();
        $ruta = $this->pdf(1);
        $servicio->vista($ruta);

        $servicio->girar($ruta, 90);

        self::assertFileDoesNotExist(PaginasDePdf::rutaDeVista($ruta), 'la vista de antes del giro se tira');
        self::assertStringStartsWith('%PDF', (string) file_get_contents($ruta, length: 4));
        self::assertStringContainsString('/Rotate 90', (string) file_get_contents($ruta));

        [$ancho, $alto] = self::medidas($servicio->vista($ruta));
        self::assertGreaterThan($alto, $ancho, 'girada 90°, la página se ve apaisada');

        // Cuatro giros de 90 vuelven al principio: el giro se SUMA, no se fija.
        $servicio->girar($ruta, 90);
        $servicio->girar($ruta, 180);
        [$ancho, $alto] = self::medidas($servicio->vista($ruta));
        self::assertGreaterThan($ancho, $alto);
    }

    public function testUnPdfRotoNoDejaRastroNiTocaElOriginal(): void
    {
        $ruta = $this->carpeta . '/roto.pdf';
        file_put_contents($ruta, '%PDF-1.4 esto no es un pdf');

        try {
            (new PaginasDePdf())->girar($ruta, 90);
            self::fail('Un PDF roto tenía que fallar.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('qpdf', $e->getMessage());
        }

        self::assertSame('%PDF-1.4 esto no es un pdf', file_get_contents($ruta));
        self::assertSame([$ruta], glob($this->carpeta . '/*'), 'sin temporales sueltos');
    }
}
