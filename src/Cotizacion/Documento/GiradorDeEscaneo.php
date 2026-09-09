<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use Doctrine\ORM\EntityManagerInterface;
use Imagick;
use ImagickException;
use RuntimeException;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Gira un escaneo torcido, **reescribiendo el fichero**.
 *
 * 🔥 **Los píxeles son la verdad, y guardar el ángulo aparte es el fallo que ya costó caro aquí.**
 * `config/packages/liip_imagine.yaml` documenta el incidente del 08/09/2026: la orientación vivía
 * en el EXIF, el navegador la respetaba y el procesador no, así que **el huésped veía su pasaporte
 * derecho, lo confirmaba, y al operador le llegaba tumbado** — sin forma de comprobarlo, porque el
 * escaneo no se le devuelve.
 *
 * Un campo `rotacion` aplicado al mostrar reintroduce ese fallo con más consumidores que antes: la
 * bóveda, el visor, la descarga del gate y **el propio lector de IA**, que leería la imagen cruda
 * y volvería a fallar. Cualquiera que se olvide de aplicarlo la ve torcida, y ninguno da error.
 *
 * ⚠️ **Sólo múltiplos rectos.** Un giro de 7° obliga a reinterpolar todos los píxeles y a rellenar
 * esquinas: se pierde nitidez justo donde importa, en la letra pequeña. 90/180/270 son una
 * permutación de píxeles y el giro en sí no pierde nada — lo que pierde es volver a escribir.
 *
 * 🔥 **CADA GIRO REENCODEA, y eso sí cuesta calidad.** Aquí decía que «el giro no toca la
 * compresión» y era **falso**. Medido sobre un escaneo real de 2400×1800 en el servidor:
 *
 * | | Peso |
 * |---|---|
 * | original | 192 KB |
 * | 1 giro | 164 KB (−14 %) |
 * | 2 giros | 156 KB (−19 %) |
 * | 4 giros | 139 KB (−27 %) |
 *
 * Es **pérdida de generación**: reescribir un webp con pérdida vuelve a cuantizar lo que ya estaba
 * cuantizado. Y `setImageCompressionQuality()` **no cambia nada** —88, 92 y 95 dan el mismo byte
 * count que el defecto—: este Imagick sólo distingue con pérdida de sin pérdida. Sin pérdida son
 * 2 426 KB, doce veces más, o sea ~1 GB para los 400 documentos del grupo: descartado.
 *
 * Una pasada es asumible; encadenarlas no. Por eso el visor **sugiere el ángulo detectado**, para
 * que el caso normal sea un solo clic. Si esto llega a molestar, el arreglo es guardar una copia
 * del fichero antes del primer giro y regirar siempre desde ella: acota la pérdida a una
 * generación para siempre, a cambio de un fichero más por documento girado.
 */
final readonly class GiradorDeEscaneo
{
    public function __construct(
        private EntityManagerInterface $em,
        private StorageInterface $almacen,
    ) {}

    public function girar(CotizacionFilearchivo $archivo, int $grados): void
    {
        $grados = ((($grados % 360) + 360) % 360);

        if (!in_array($grados, [90, 180, 270], true)) {
            throw new RuntimeException('Sólo se puede girar 90, 180 o 270 grados.');
        }

        $ruta = $this->almacen->resolvePath($archivo, 'imageFile');
        if (!is_string($ruta) || !is_readable($ruta) || !is_writable($ruta)) {
            throw new RuntimeException('No se puede escribir sobre ese fichero.');
        }

        // Un PDF no se gira con Imagick sin rasterizarlo, y rasterizar un PDF de identidad para
        // enderezarlo cambia el fichero por uno peor. Se rechaza con una frase en vez de dejar
        // un PDF convertido en imagen sin que nadie lo pidiera.
        if (str_ends_with(strtolower($ruta), '.pdf')) {
            throw new RuntimeException('Los PDF no se giran aquí: vuelve a subirlo derecho.');
        }

        try {
            $imagen = new Imagick($ruta);
            $imagen->rotateImage('none', $grados);
            $imagen->writeImage($ruta);
            $imagen->clear();
        } catch (ImagickException $e) {
            throw new RuntimeException('No se pudo girar: ' . $e->getMessage(), 0, $e);
        }

        // 🔑 **Se tira la lectura.** Un documento torcido casi siempre se leyó mal —es la razón de
        // girarlo—, así que conservar esa lectura dejaría el veredicto apoyado en lo que se leyó
        // del revés. Al quedar sin lectura, la siguiente tanda lo vuelve a leer ya derecho: cuesta
        // ~$0,0016 y es exactamente lo que se quería conseguir girándolo.
        $archivo->registrarLectura(null);
        $archivo->setImageSize(filesize($ruta) ?: $archivo->getImageSize());

        // El `preUpdate` de `CotizacionFilearchivoCacheListener` limpia la caché de Liip, así que
        // las miniaturas se regeneran solas. Sin este flush seguirían enseñando la versión vieja.
        $this->em->flush();
    }
}
