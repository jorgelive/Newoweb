<?php

declare(strict_types=1);

namespace App\Message\Service\Translation;

use LanguageDetection\Language;
use ReflectionClass;

/**
 * El idioma de un mensaje entrante, elegido SÓLO entre los idiomas del maestro.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El detector anterior llamaba a la librería sin restricción —`new Language()`, sus 110 idiomas—
 * aunque el comentario de al lado decía «restringimos la detección SOLO a los idiomas que soporta
 * el PMS». Con frases cortas acierta poco y elige idiomas que no tenemos: «Parfait merci !» salía
 * `ch` (chamorro), «Muchas gracias Susan» `gd` (gaélico), «Thank you! Have a great evening» `fo`
 * (feroés). Y quien lo llamaba, al no encontrar ese idioma en el maestro, **caía a inglés** y le
 * cambiaba el idioma a la conversación: el siguiente mensaje del equipo le salió en inglés a una
 * huésped francesa (Clémence Mougin, 14/10/2026).
 *
 * Medido sobre 60 días de entrantes: el 23 % caía fuera del maestro, o sea a inglés.
 *
 * ── La regla ────────────────────────────────────────────────────────────────
 * 1. **Sólo idiomas del maestro.** La librería se carga con los códigos que les corresponden; un
 *    idioma que no tenemos no puede ganar.
 * 2. **Lo que no se puede decidir conserva el idioma actual**, nunca inglés: un texto demasiado
 *    corto ({@see LongitudParaIdioma}) o un resultado vacío.
 * 3. **Cambiar cuesta más que quedarse.** Si el idioma actual puntúa a menos de
 *    {@see self::MARGEN_PARA_CAMBIAR} del ganador, gana el actual. La librería es floja justo
 *    donde más duele: «Me confirmas porfa» da portugués 0,533 y español 0,504. Equivocarse al
 *    quedarse no cuesta nada —el siguiente mensaje largo vuelve a decidir—; equivocarse al
 *    cambiar manda el siguiente mensaje del equipo en otro idioma.
 *
 * ── El margen, medido (17/10/2026, 542 entrantes de 60 días) ────────────────
 *
 * ```
 * margen   hilos con algún cambio falso   hilos en otro idioma que SÍ cambian (desde «es»)
 * 0,00             25 de 59                        21 de 21
 * 0,04             12 de 59                        21 de 21     ← elegido
 * 0,06              8 de 59                        20 de 21
 * 0,08              6 de 59                        17 de 21
 * ```
 *
 * Mensaje a mensaje, el detector anterior cambiaba el idioma equivocadamente **121** veces; éste,
 * **27**. Más margen sigue bajando los falsos, pero empieza a perder hilos que sí había que
 * cambiar, y ésos sólo se arreglan a mano.
 *
 * Sin base de datos y sin contenedor a propósito: es lo que se prueba. Los códigos del maestro se
 * los da {@see GuestLanguageDetectorService}.
 */
final class DetectorDeIdiomaLocal
{
    /** Cuánto tiene que sacarle el ganador al idioma actual para cambiar. Ver la tabla de arriba. */
    public const float MARGEN_PARA_CAMBIAR = 0.04;

    private readonly Language $libreria;

    /** @param list<string> $idiomasDelMaestro Ids de `maestro_idioma`: `es`, `pt`, `zh`… */
    public function __construct(array $idiomasDelMaestro)
    {
        $this->libreria = new Language(self::codigosDeLibreriaPara($idiomasDelMaestro));
    }

    /** El idioma del texto entre los del maestro, o `$actual` si no se puede decidir. */
    public function codigoDe(string $texto, string $actual): string
    {
        if (!LongitudParaIdioma::basta($texto)) {
            return $actual;
        }

        $puntuaciones = $this->puntuaciones($texto);
        $ganador = array_key_first($puntuaciones);

        if ($ganador === null) {
            return $actual;
        }

        // El actual puede venir en varias variantes de la librería (`pt-BR` y `pt-PT`): cuenta la mejor.
        $delActual = 0.0;

        foreach ($puntuaciones as $codigo => $puntuacion) {
            if (self::aMaestro($codigo) === $actual) {
                $delActual = max($delActual, $puntuacion);
            }
        }

        return ($puntuaciones[$ganador] - $delActual) <= self::MARGEN_PARA_CAMBIAR
            ? $actual
            : self::aMaestro($ganador);
    }

    /**
     * Las puntuaciones de la librería, de mayor a menor y tipadas.
     *
     * `LanguageResult::close()` devuelve un array sin tipo: se convierte aquí, una vez, y lo que no
     * sea un número se descarta. Se ordena aquí en vez de fiarse del orden en que llega.
     *
     * @return array<string, float>
     */
    private function puntuaciones(string $texto): array
    {
        $puntuaciones = [];

        foreach ($this->libreria->detect(trim(strip_tags($texto)))->close() as $codigo => $valor) {
            if (is_numeric($valor)) {
                $puntuaciones[(string) $codigo] = (float) $valor;
            }
        }

        arsort($puntuaciones);

        return $puntuaciones;
    }

    /**
     * El id del maestro para un código de la librería.
     *
     * La librería distingue variantes que el maestro no: `pt-BR`/`pt-PT`, `zh-Hans`/`zh-Hant`,
     * `el-monoton`, `ms-Latn`… Todas se quedan con lo de antes del guion. Y el noruego, que allí
     * es `nb` (bokmål) o `nn` (nynorsk) y en el maestro `no`.
     */
    public static function aMaestro(string $codigoDeLibreria): string
    {
        $base = strtolower(explode('-', $codigoDeLibreria, 2)[0]);

        return match ($base) {
            'nb', 'nn' => 'no',
            default => $base,
        };
    }

    /**
     * Los códigos de la librería cuyo idioma está en el maestro.
     *
     * Se deducen de los recursos de la propia librería y no de una lista escrita aquí: así un
     * idioma nuevo en el maestro entra solo, y una variante que no conocíamos (`pt-BR`) también.
     *
     * @param list<string> $idiomasDelMaestro
     *
     * @return list<string>
     */
    public static function codigosDeLibreriaPara(array $idiomasDelMaestro): array
    {
        $fichero = (new ReflectionClass(Language::class))->getFileName();
        $directorios = $fichero !== false ? (glob(\dirname($fichero, 3) . '/resources/*', GLOB_ONLYDIR) ?: []) : [];

        $codigos = [];

        foreach ($directorios as $directorio) {
            $codigo = basename($directorio);

            if (\in_array(self::aMaestro($codigo), $idiomasDelMaestro, true)) {
                $codigos[] = $codigo;
            }
        }

        return $codigos;
    }
}
