<?php

declare(strict_types=1);

namespace App\Tests\Panel\Media;

use App\Panel\Media\ImagenSubidaSegura;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * El portero de las subidas que acaban en `public/`. Lo que se prueba es que mira el CONTENIDO:
 * la extensión y el `Content-Type` que manda el cliente son texto que escribe quien sube.
 */
#[CoversClass(ImagenSubidaSegura::class)]
final class ImagenSubidaSeguraTest extends TestCase
{
    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
    }

    public function testUnPngPasa(): void
    {
        $subida = $this->subida($this->png(), 'foto.png');

        ImagenSubidaSegura::verificar($subida);

        self::assertContains($subida->getMimeType(), ImagenSubidaSegura::MIMES);
    }

    /** El caso que motivó la clase: XSS almacenado en el mismo origen que el panel. */
    public function testUnSvgConScriptNoPasa(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

        $this->expectException(UnprocessableEntityHttpException::class);
        ImagenSubidaSegura::verificar($this->subida($svg, 'foto.svg'));
    }

    /** Disfrazado de foto: el nombre y el tipo declarado mienten, el contenido no. */
    public function testUnHtmlConNombreDeFotoNoPasa(): void
    {
        $html = "<!doctype html>\n<html><body><script>fetch('/api')</script></body></html>";

        $this->expectException(UnprocessableEntityHttpException::class);
        ImagenSubidaSegura::verificar($this->subida($html, 'vacaciones.png', 'image/png'));
    }

    public function testUnPhpNoPasa(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        ImagenSubidaSegura::verificar($this->subida("<?php echo 'hola';", 'shell.php'));
    }

    private function subida(string $contenido, string $nombre, string $tipoDeclarado = 'image/png'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'subida_');
        self::assertIsString($ruta);
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        // El quinto parámetro es el modo de prueba: permite instanciarlo sin una subida HTTP real.
        return new UploadedFile($ruta, $nombre, $tipoDeclarado, null, true);
    }

    /** Un PNG de 1×1 de verdad, para que `fileinfo` lo reconozca por su contenido. */
    private function png(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);

        return $png;
    }
}
