<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Formato;

use App\Message\Service\Formato\FormatoDeTexto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los bloques de código que emiten los modelos, y por qué NO pueden llegar al huésped.
 *
 * El caso es real (19/08/2026, panel de operación): al pedir el mensaje de prepago de una
 * reserva, el modelo contestó con una introducción, el localizador entre acentos graves y el
 * mensaje envuelto en ```text … ```. Nadie trataba esos acentos: WhatsApp los pinta como
 * monoespaciado y Beds24 los manda literales.
 *
 * La regla es asimétrica a propósito: **el canal QUITA la marca y el panel la PINTA**. Al
 * huésped le llega el contenido; al operador, un `<pre>` que es justo lo que copia el botón.
 *
 * Unitario puro.
 */
final class FormatoDeTextoBloquesTest extends TestCase
{
    /** El mensaje tal y como lo devolvió el modelo en producción, recortado. */
    private const string REAL = <<<'TXT'
        Aquí tienes el mensaje de prepago para *Miguel Angel* (Localizador: `J34NN5`):

        ```text
        🏨 DETALLE DE RESERVA - Casita 2
        ✅ TOTAL DE LA RESERVA: US$ 108.15
        ```
        TXT;

    /** @return iterable<string, array{string, string}> */
    public static function textos(): iterable
    {
        yield 'el bloque pierde las comillas y conserva el contenido' => [
            "antes\n```text\ncuerpo\n```\ndespués",
            "antes\ncuerpo\ndespués",
        ];
        yield 'bloque sin lenguaje' => ["```\ncuerpo\n```", 'cuerpo'];
        yield 'código en línea' => ['el localizador es `J34NN5` hoy', 'el localizador es J34NN5 hoy'];
        yield 'dos en línea' => ['`a` y `b`', 'a y b'];
        yield 'un acento suelto no se toca' => ['cuesta 5` de más', 'cuesta 5` de más'];
    }

    #[Test]
    #[DataProvider('textos')]
    public function el_canal_se_queda_con_el_contenido(string $entra, string $sale): void
    {
        self::assertSame($sale, (new FormatoDeTexto())->paraTextoPlano($entra));
    }

    #[Test]
    public function el_mensaje_real_sale_limpio_por_los_dos_canales(): void
    {
        $f = new FormatoDeTexto();

        foreach (['paraWhatsapp', 'paraTextoPlano'] as $canal) {
            $salida = $f->{$canal}(self::REAL);

            self::assertStringNotContainsString('```', $salida, "$canal deja el bloque");
            self::assertStringNotContainsString('`', $salida, "$canal deja acentos graves");
            self::assertStringContainsString('US$ 108.15', $salida, "$canal se comió el contenido");
            self::assertStringContainsString('J34NN5', $salida, "$canal se comió el localizador");
        }
    }

    #[Test]
    public function es_idempotente(): void
    {
        // Importa porque `normalizar()` se aplica también sobre texto que ya pasó por aquí.
        $f = new FormatoDeTexto();
        $una = $f->paraWhatsapp(self::REAL);

        self::assertSame($una, $f->paraWhatsapp($una));
    }

    #[Test]
    public function una_url_dentro_de_un_bloque_no_se_rompe(): void
    {
        $f = new FormatoDeTexto();
        $salida = $f->paraWhatsapp("```\nPaga aquí: https://openperu.pe/pago_1?a=b\n```");

        self::assertStringContainsString('https://openperu.pe/pago_1?a=b', $salida);
    }
}
