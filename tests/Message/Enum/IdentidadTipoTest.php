<?php

declare(strict_types=1);

namespace App\Tests\Message\Enum;

use App\Message\Enum\IdentidadTipo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La normalización de un identificador.
 *
 * Es la pieza de la que depende que `(tipo, valor)` sirva como clave: si dos formas del mismo
 * correo no se normalizan igual, acaban en dos hilos — el problema que la tabla vino a cerrar.
 */
final class IdentidadTipoTest extends TestCase
{
    #[Test]
    #[DataProvider('correos')]
    public function elCorreoSeGuardaEnMinusculasYSinEspacios(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, IdentidadTipo::EMAIL->normalizar($entrada));
    }

    /** @return iterable<string, array{string, string}> */
    public static function correos(): iterable
    {
        yield 'tal cual' => ['nune@ejemplo.com', 'nune@ejemplo.com'];
        yield 'mayúsculas' => ['Nune@Ejemplo.COM', 'nune@ejemplo.com'];
        yield 'con espacios' => ['  nune@ejemplo.com  ', 'nune@ejemplo.com'];
        yield 'pegado de un correo' => ["\tNUNE@ejemplo.com\n", 'nune@ejemplo.com'];
    }

    #[Test]
    #[DataProvider('telefonos')]
    public function elTelefonoSeQuedaSoloEnDigitos(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, IdentidadTipo::TELEFONO->normalizar($entrada));
    }

    /** @return iterable<string, array{string, string}> */
    public static function telefonos(): iterable
    {
        yield 'como lo manda WhatsApp' => ['51984123456', '51984123456'];
        yield 'con el más delante' => ['+51984123456', '51984123456'];
        yield 'con espacios' => ['+51 984 123 456', '51984123456'];
        yield 'con guiones' => ['+51-984-123-456', '51984123456'];
        yield 'con paréntesis' => ['(+51) 984 123456', '51984123456'];
    }

    /**
     * ⚠️ **El `+` se descarta, y ese detalle importa.**
     *
     * Se conservaba, y era una partición esperando: `+51984123456` y `51984123456` son el mismo
     * número y daban DOS identidades — o sea dos hilos para la misma persona, que es justo lo
     * que esta tabla vino a impedir.
     *
     * No saltó nunca porque todos los valores entraron por el mismo sitio: en producción no hay
     * ni uno con `+` (0 de 280 identidades, 0 de 298 teléfonos de reserva). La puerta la abre el
     * editor manual, donde alguien lo teclea como se dice.
     */
    #[Test]
    public function elMismoNumeroConYSinElMasEsElMismo(): void
    {
        self::assertSame(
            IdentidadTipo::TELEFONO->normalizar('51984123456'),
            IdentidadTipo::TELEFONO->normalizar('+51 984 123 456')
        );
    }

    /**
     * ⚠️ El mismo número con y sin prefijo NO normaliza igual, y es correcto: son valores
     * distintos y unirlos sería una decisión, no una limpieza. De eso se encarga la búsqueda
     * por cola, que **sugiere** y deja constancia.
     */
    #[Test]
    public function elPrefijoNoSeAdivinaAlNormalizar(): void
    {
        self::assertNotSame(
            IdentidadTipo::TELEFONO->normalizar('+51 984 123 456'),
            IdentidadTipo::TELEFONO->normalizar('984123456')
        );
    }

    /**
     * El `bookId` de Beds24: dígitos y nada más.
     *
     * Es el identificador de los hilos que nacen **sin teléfono y sin correo** —una reserva de
     * OTA cuyo huésped todavía no ha escrito— y aun así son alcanzables, porque la salida por
     * Beds24 se dirige con él. Llega unas veces como entero y otras como texto.
     */
    #[Test]
    public function elBookIdDeBeds24SeQuedaEnDigitos(): void
    {
        self::assertSame('88591163', IdentidadTipo::BEDS24->normalizar('88591163'));
        self::assertSame('88591163', IdentidadTipo::BEDS24->normalizar(' 88591163 '));
        self::assertSame('88591163', IdentidadTipo::BEDS24->normalizar('#88591163'));
    }

    #[Test]
    public function loQueNoTieneNadaUtilQuedaVacio(): void
    {
        self::assertSame('', IdentidadTipo::TELEFONO->normalizar('   '));
        self::assertSame('', IdentidadTipo::TELEFONO->normalizar('sin número'));
        self::assertSame('', IdentidadTipo::EMAIL->normalizar('  '));
        self::assertSame('', IdentidadTipo::BEDS24->normalizar('sin id'));
    }
}
