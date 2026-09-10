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
    /**
     * La copia intacta vive **al lado**, con este sufijo.
     *
     * ⚠️ Hace falta **borrarla con el archivo**: es un documento de identidad, y la política de
     * retención no distingue entre el fichero y su copia. De eso se encarga
     * {@see \App\Cotizacion\EventListener\EscaneoOriginalListener}.
     */
    public const SUFIJO_ORIGINAL = '.original';

    public function __construct(
        private EntityManagerInterface $em,
        private StorageInterface $almacen,
    ) {}

    /**
     * La lectura, con la orientación puesta al día — sin volver a leer nada.
     *
     * Si al documento le faltaban `r` grados y le aplicamos `g`, ahora le faltan `r − g`. Es
     * aritmética, no una observación: el giro lo hicimos nosotros y sabemos exactamente cuánto.
     *
     * ⚠️ `null` cuando nunca se leyó, y entonces sigue sin leerse: no se inventa una lectura.
     *
     * @param array<string, mixed>|null $leido
     * @return array<string, mixed>|null
     */
    private static function conOrientacionCorregida(?array $leido, int $grados): ?array
    {
        if ($leido === null) {
            return null;
        }

        $faltaban = Orientacion::grados(is_string($leido['bordeSuperior'] ?? null) ? $leido['bordeSuperior'] : null);
        $leido['bordeSuperior'] = Orientacion::borde($faltaban - $grados);

        return $leido;
    }

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

        // 🔑 **La copia intacta, guardada ANTES del primer giro.** Sin ella, cada giro reescribe
        // sobre lo ya reescrito: −14 % el primero, −27 % al cuarto. Con ella, girar diez veces
        // cuesta lo mismo que girar una, porque siempre se parte del original.
        $original = $ruta . self::SUFIJO_ORIGINAL;

        // ⚠️ **`link()` y no `file_exists()` + `copy()`.** Comprobar y luego copiar son dos pasos, y
        // entre ellos cabe otra petición: dos giros a la vez y el segundo copiaría como «original»
        // el fichero que el primero acaba de girar — original falso y doble giro para siempre.
        // `link()` crea sólo si no existe, en una sola operación del sistema de ficheros.
        if (!file_exists($original)) {
            // El `@` es deliberado y acotado: que falle porque otro lo creó primero es el caso
            // NORMAL de la carrera, no un error. Cualquier otro fallo lo destapa el `is_file()`.
            @link($ruta, $original);
        }

        if (!is_file($original)) {
            throw new RuntimeException('No se pudo guardar la copia original antes de girar.');
        }

        $acumulado = ((($archivo->getRotacionAplicada() + $grados) % 360) + 360) % 360;

        // ⚠️ Un enlace duro comparte inodo: escribir sobre el fichero vivo con `writeImage()` lo
        // reemplaza (crea y renombra), así que el original sobrevive. Pero si alguna vez se
        // escribiera EN SITIO, los dos cambiarían a la vez. Se rompe el enlace en cuanto hay algo
        // que preservar de verdad.
        if ($archivo->getRotacionAplicada() === 0 && fileinode($ruta) === fileinode($original)) {
            $contenido = (string) file_get_contents($original);
            unlink($original);
            file_put_contents($original, $contenido);
        }

        try {
            if ($acumulado === 0) {
                // Vuelta al punto de partida: se restaura el original **tal cual**. Girar 90° para
                // completar los 360 volvería a reencodear y dejaría peor lo que ya estaba bien.
                if (!copy($original, $ruta)) {
                    throw new RuntimeException('No se pudo restaurar el original.');
                }
            } else {
                $imagen = new Imagick($original);
                $imagen->rotateImage('none', $acumulado);
                $imagen->writeImage($ruta);
                $imagen->clear();
            }
        } catch (ImagickException $e) {
            throw new RuntimeException('No se pudo girar: ' . $e->getMessage(), 0, $e);
        }

        $archivo->setRotacionAplicada($acumulado);

        // 🔑 **La lectura se CORRIGE, no se tira — y eso es lo que quita los 16 segundos.**
        //
        // Antes se borraba y se revalidaba a la persona en el acto, lo que obligaba a una llamada
        // a la IA (3,5 s) y a que el front recargase el expediente entero. Pero **girar no cambia
        // lo que dice el documento**: el número, las fechas y la MRZ son los mismos. Lo único que
        // deja de ser cierto es la orientación, y ésa se sabe sin preguntarle a nadie — acabamos
        // de aplicarla nosotros.
        //
        // Un escaneo que se leyó BIEN estando torcido no necesita releerse por enderezarlo.
        $archivo->registrarLectura(self::conOrientacionCorregida($archivo->getDatosLeidos(), $grados));
        $archivo->setImageSize(filesize($ruta) ?: $archivo->getImageSize());

        // El `preUpdate` de `CotizacionFilearchivoCacheListener` limpia la caché de Liip, así que
        // las miniaturas se regeneran solas. Sin este flush seguirían enseñando la versión vieja.
        $this->em->flush();

        // ⚠️ **Ya NO se revalida a la persona aquí.** El aviso de giro salía del veredicto, así
        // que enderezar obligaba a recalcularlo —una llamada a la IA y una recarga del expediente
        // por cada clic—. Y encima sólo cubría el escaneo que respalda ese número: girar el
        // anverso hacía desaparecer el aviso con el reverso todavía torcido.
        //
        // El giro es una propiedad del ARCHIVO, no del veredicto. Vive en `datos_leidos` y la
        // pantalla lo lee de ahí, escaneo por escaneo.
    }
}
