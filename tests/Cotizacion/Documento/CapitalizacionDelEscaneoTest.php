<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Pms\Nombre\OrdenDelNombre;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La caja de los nombres que salen de un documento escaneado.
 *
 * ── Por qué se prueba el guardián y no el lector ────────────────────────────
 * `LectorDeDocumentoIdentidad::capitalizado()` no decide nada: pregunta a
 * {@see OrdenDelNombre::conLaCajaBuena()}, que es quien puede equivocarse. Y el lector no se
 * puede ejercitar sin una llamada al modelo, así que probarlo sería probar un doble.
 *
 * ── Lo que de verdad hay que fijar ──────────────────────────────────────────
 * Un documento imprime «DIAZ ARREDONDO» y la app muestra nombres capitalizados, así que la caja
 * la propone el mismo modelo que lee el escaneo. El riesgo no es que capitalice mal: es que
 * **cambie una letra por el camino**. Sobre un escaneo eso es más probable que en el PMS, porque
 * el OCR confunde `0` con `O` y `1` con `I`, y una propuesta «bonita» taparía el error de lectura
 * justo donde nadie va a volver a mirar.
 */
final class CapitalizacionDelEscaneoTest extends TestCase
{
    #[Test]
    #[DataProvider('casosQueSeAceptan')]
    public function la_caja_propuesta_se_aplica(string $documento, string $propuesta, string $esperado): void
    {
        self::assertSame($esperado, OrdenDelNombre::conLaCajaBuena($documento, $propuesta));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function casosQueSeAceptan(): iterable
    {
        yield 'el caso que lo motivó' => ['DIAZ ARREDONDO', 'Diaz Arredondo', 'Diaz Arredondo'];
        yield 'nombre compuesto' => ['RAY DANTE', 'Ray Dante', 'Ray Dante'];
        yield 'partículas' => ['DE LA CRUZ', 'de la Cruz', 'de la Cruz'];
        // La MRZ va sin tildes; lo impreso sí las lleva. Añadirlas es caja, no otra letra.
        yield 'con tildes' => ['JOSE PEREZ NUNEZ', 'José Pérez Núñez', 'José Pérez Núñez'];
    }

    #[Test]
    #[DataProvider('casosQueSeRechazan')]
    public function una_propuesta_que_cambia_letras_se_descarta(string $documento, string $propuesta): void
    {
        self::assertSame(
            $documento,
            OrdenDelNombre::conLaCajaBuena($documento, $propuesta),
            'Si difiere en algo más que caja y tildes, manda lo que dice el documento.',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function casosQueSeRechazan(): iterable
    {
        // El fallo típico del OCR: un CERO donde va una O. La propuesta «arregla» la letra, y
        // aceptarla sería que el sistema decidiera por su cuenta que el documento está mal.
        yield 'cero por O' => ['B0UZA', 'Bouza'];
        // Un UNO por una I: la propuesta parece más correcta, y por eso es peligrosa.
        yield 'uno por I' => ['MART1NEZ', 'Martínez'];
        yield 'letra de más' => ['PEREZ', 'Pereza'];
        yield 'traducido' => ['JOHN', 'Juan'];
    }

    /**
     * Un nombre que YA viene con caja mixta no se toca.
     *
     * Es `mereceCapitalizacion()`: si el original mezcla mayúsculas y minúsculas, la caja ya
     * informa —alguien la escribió a conciencia— y no hay nada que arreglar. En un escaneo esto
     * cubre el documento que imprime «McDONALD» o «van Dijk» tal cual.
     */
    #[Test]
    public function lo_que_ya_tiene_caja_mixta_se_respeta(): void
    {
        self::assertSame('van Dijk', OrdenDelNombre::conLaCajaBuena('van Dijk', 'Van Dijk'));
    }

    /** Sin propuesta no hay nada que aplicar: se queda lo que dice el documento. */
    #[Test]
    public function sin_propuesta_manda_el_documento(): void
    {
        self::assertSame('DIAZ ARREDONDO', OrdenDelNombre::conLaCajaBuena('DIAZ ARREDONDO', ''));
    }
}
