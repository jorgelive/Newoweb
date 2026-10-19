<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use Imagick;
use ImagickException;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Lo que se le hace a un PDF subido —girarlo y enseñarlo—, **en el servidor y sin tocar su contenido**.
 *
 * Casi la mitad de lo que suben los pasajeros es PDF (330 de 681 el 19/10/2026), y hasta entonces
 * en el visor eran un icono: «No se puede previsualizar aquí», y girarlos se rechazaba.
 *
 * ── Girar: el atributo `/Rotate`, no los píxeles ──────────────────────────────
 * Una imagen se gira reescribiendo sus píxeles ({@see GiradorDeEscaneo}), porque guardar el ángulo
 * aparte ya costó caro: el EXIF que unos respetaban y otros no. Un PDF tiene algo mejor: cada página
 * lleva su giro **dentro del propio fichero**, y lo respeta cualquier lector de PDF —el navegador,
 * el visor del móvil, el lector de IA—. Cambiarlo con `qpdf` no recomprime nada, así que girar diez
 * veces no pierde calidad y no hace falta la copia `.original` de las imágenes.
 *
 * Rasterizarlo para girarlo, que era la otra salida, cambiaría el documento por una foto suya.
 *
 * ── Enseñar: una imagen de las primeras páginas, aparte ──────────────────────
 * {@see self::vista()} pinta hasta {@see self::MAX_PAGINAS} páginas en un WebP que vive **al lado**
 * del PDF (`<pdf>.vista.webp`), en la carpeta privada, y lo sirve el mismo controlador con el mismo
 * permiso. El PDF no se toca: el documento sigue siendo él.
 *
 * ⚠️ **Ghostscript con PDFs que sube cualquiera.** Ha tenido fallos graves con ficheros
 * manipulados, así que: `-dSAFER`, un tope de páginas y de resolución, un tiempo máximo, y se
 * genera **al pedir la vista** —en el panel, con sesión—, nunca en la subida pública.
 *
 * Los binarios (`qpdf`, `gs`) se buscan también fuera del PATH: el de php-fpm no siempre incluye
 * `/opt/homebrew/bin` en el Mac. Ver docs/Cotizaciones.md.
 */
final readonly class PaginasDePdf
{
    /** La vista vive junto al PDF con este sufijo. Hay que borrarla con él: ver `EscaneoDerivadosListener`. */
    public const string SUFIJO_VISTA = '.vista.webp';

    /** Anverso, reverso y algo más: un documento de identidad no pasa de aquí. */
    private const int MAX_PAGINAS = 4;

    /** Un A4 a 110 ppp sale a ~900 px de ancho: se lee la letra del pasaporte sin pesar un mundo. */
    private const int RESOLUCION = 110;

    private const int ANCHO_MAXIMO = 1400;

    private const int SEGUNDOS = 30;

    /** Dónde buscar los binarios además del PATH. */
    private const array CARPETAS = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'];

    public static function esPdf(string $ruta): bool
    {
        return str_ends_with(strtolower($ruta), '.pdf');
    }

    /** Ruta de la vista de ese PDF, exista o no. */
    public static function rutaDeVista(string $ruta): string
    {
        return $ruta . self::SUFIJO_VISTA;
    }

    /**
     * Gira todas las páginas `$grados` en sentido horario, sumándolos a lo que ya tuvieran.
     *
     * A un temporal y luego `rename()`: si qpdf falla a medias, el PDF de verdad no se ha tocado.
     */
    public function girar(string $ruta, int $grados): void
    {
        $temporal = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            // qpdf sale con 3 cuando escribió el fichero pero avisó de algo (un PDF con defectos
            // menores, muy habitual en los que salen de un móvil): el giro está hecho.
            $this->ejecutar([$this->binario('qpdf'), $ruta, '--rotate=+' . $grados, $temporal], [0, 3]);

            if (!is_file($temporal) || filesize($temporal) === 0) {
                throw new RuntimeException('qpdf no dejó el PDF girado.');
            }

            if (!rename($temporal, $ruta)) {
                throw new RuntimeException('No se pudo reemplazar el PDF por el girado.');
            }
        } finally {
            if (is_file($temporal)) {
                unlink($temporal);
            }
        }

        // La vista enseñaba el PDF sin girar. Se tira para que la siguiente petición la rehaga.
        $vista = self::rutaDeVista($ruta);
        if (is_file($vista)) {
            unlink($vista);
        }
    }

    /**
     * La imagen de las primeras páginas, generándola si no existe o si el PDF es más nuevo.
     *
     * @return string ruta absoluta del WebP
     */
    public function vista(string $ruta): string
    {
        $vista = self::rutaDeVista($ruta);

        clearstatcache(true, $vista);
        if (is_file($vista) && filemtime($vista) >= filemtime($ruta)) {
            return $vista;
        }

        $carpeta = sys_get_temp_dir() . '/vista-pdf-' . bin2hex(random_bytes(6));
        if (!mkdir($carpeta, 0700) && !is_dir($carpeta)) {
            throw new RuntimeException('No se pudo preparar la carpeta temporal de la vista.');
        }

        try {
            $this->ejecutar([
                $this->binario('gs'),
                '-dSAFER',
                '-dBATCH',
                '-dNOPAUSE',
                '-dQUIET',
                '-sDEVICE=png16m',
                '-r' . self::RESOLUCION,
                '-dTextAlphaBits=4',
                '-dGraphicsAlphaBits=4',
                '-dFirstPage=1',
                '-dLastPage=' . self::MAX_PAGINAS,
                '-o',
                $carpeta . '/p%02d.png',
                $ruta,
            ]);

            $paginas = glob($carpeta . '/p*.png') ?: [];
            sort($paginas);

            if ($paginas === []) {
                throw new RuntimeException('El PDF no tiene páginas que pintar.');
            }

            $this->escribirWebp($paginas, $vista);
        } finally {
            foreach (glob($carpeta . '/*') ?: [] as $fichero) {
                unlink($fichero);
            }
            rmdir($carpeta);
        }

        return $vista;
    }

    /**
     * Las páginas una debajo de otra, con una franja gris entre ellas para que se vea dónde
     * acaba cada una.
     *
     * @param list<string> $paginas
     */
    private function escribirWebp(array $paginas, string $destino): void
    {
        $temporal = $destino . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $pila = new Imagick();
            foreach ($paginas as $i => $pagina) {
                if ($i > 0) {
                    $separador = new Imagick();
                    $separador->newImage(10, 12, 'rgb(203,213,225)');
                    $pila->addImage($separador);
                }
                $pila->readImage($pagina);
            }

            $pila->setImageBackgroundColor('rgb(203,213,225)');
            $pila->resetIterator();
            $imagen = $pila->appendImages(true);

            if ($imagen->getImageWidth() > self::ANCHO_MAXIMO) {
                $imagen->thumbnailImage(self::ANCHO_MAXIMO, 0);
            }

            $imagen->setImageFormat('webp');
            $imagen->setImageCompressionQuality(80);
            $imagen->writeImage('webp:' . $temporal);
            $imagen->clear();
            $pila->clear();

            if (!rename($temporal, $destino)) {
                throw new RuntimeException('No se pudo guardar la vista del PDF.');
            }
        } catch (ImagickException $e) {
            throw new RuntimeException('No se pudo componer la vista del PDF: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_file($temporal)) {
                unlink($temporal);
            }
        }
    }

    /**
     * @param list<string> $comando
     * @param list<int> $salidasBuenas
     */
    private function ejecutar(array $comando, array $salidasBuenas = [0]): void
    {
        $proceso = new Process($comando);
        $proceso->setTimeout(self::SEGUNDOS);
        $proceso->run();

        if (!in_array($proceso->getExitCode(), $salidasBuenas, true)) {
            $error = trim($proceso->getErrorOutput() ?: $proceso->getOutput());

            throw new RuntimeException(sprintf(
                '%s falló (salida %s)%s',
                basename($comando[0]),
                (string) $proceso->getExitCode(),
                $error === '' ? '.' : ': ' . mb_substr($error, 0, 200)
            ));
        }
    }

    private function binario(string $nombre): string
    {
        return (new ExecutableFinder())->find($nombre, null, self::CARPETAS)
            ?? throw new RuntimeException(sprintf('No encuentro «%s» en el servidor: hace falta para los PDF.', $nombre));
    }
}
