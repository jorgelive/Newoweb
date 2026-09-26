<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Formato;

use App\Message\Service\Formato\HidratadorDeMarcadores;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La tabla de la cabecera de {@see HidratadorDeMarcadores}, fila por fila. Es la sustitución que
 * usan el envío y `msg:plantilla:ver`, así que lo que salga aquí es lo que lee el huésped.
 */
#[CoversClass(HidratadorDeMarcadores::class)]
final class HidratadorDeMarcadoresTest extends TestCase
{
    #[Test]
    public function sustituye_con_y_sin_espacios(): void
    {
        $h = new HidratadorDeMarcadores();

        self::assertSame('Hola Ana, llegas el 20', $h->hidratar('Hola {{ guest_name }}, llegas el {{checkin_day}}', [
            'guest_name' => 'Ana',
            'checkin_day' => 20,
        ]));
    }

    /** `bloque_pago` vale vacío a propósito cuando no hay nada que cobrar. */
    #[Test]
    public function la_clave_que_existe_vacia_no_deja_nada(): void
    {
        self::assertSame('Hola .', (new HidratadorDeMarcadores())->hidratar('Hola {{ bloque_pago }}.', ['bloque_pago' => null]));
    }

    #[Test]
    public function la_clave_que_no_existe_deja_el_marcador_para_que_se_vea(): void
    {
        self::assertSame('Paga {{ importe }}', (new HidratadorDeMarcadores())->hidratar('Paga {{ importe }}', ['otra' => 'x']));
    }

    /** Antes salía la palabra «Array» dentro del mensaje, con un warning en el log. */
    #[Test]
    public function un_valor_que_no_es_texto_deja_el_marcador_en_vez_de_array(): void
    {
        self::assertSame('Tus casitas: {{ casitas }}', (new HidratadorDeMarcadores())->hidratar('Tus casitas: {{ casitas }}', [
            'casitas' => ['Casita 1', 'Casita 2'],
        ]));
    }

    /** Lo que tiene forma de texto se escribe como el `(string)` de siempre. */
    #[Test]
    public function como_texto_es_el_cast_para_lo_que_tiene_forma_de_texto(): void
    {
        self::assertSame('3', HidratadorDeMarcadores::comoTexto(3));
        self::assertSame('60.5', HidratadorDeMarcadores::comoTexto(60.5));
        self::assertSame('', HidratadorDeMarcadores::comoTexto(null));
        self::assertSame('1', HidratadorDeMarcadores::comoTexto(true));
    }

    /** Una lista no tiene forma de texto: quien sustituye deja el marcador (ver la cabecera). */
    #[Test]
    public function como_texto_de_una_lista_es_null(): void
    {
        self::assertNull(HidratadorDeMarcadores::comoTexto(['a', 'b']));
    }
}
