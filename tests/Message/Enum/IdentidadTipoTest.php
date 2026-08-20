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
    public function elTelefonoSeQuedaEnDigitosYMas(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, IdentidadTipo::TELEFONO->normalizar($entrada));
    }

    /** @return iterable<string, array{string, string}> */
    public static function telefonos(): iterable
    {
        yield 'ya limpio' => ['+51984123456', '+51984123456'];
        yield 'con espacios' => ['+51 984 123 456', '+51984123456'];
        yield 'con guiones' => ['+51-984-123-456', '+51984123456'];
        yield 'con paréntesis' => ['(+51) 984 123456', '+51984123456'];
        yield 'como lo manda WhatsApp' => ['51984123456', '51984123456'];
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
            IdentidadTipo::TELEFONO->normalizar('+51984123456'),
            IdentidadTipo::TELEFONO->normalizar('984123456')
        );
    }

    #[Test]
    public function loQueNoTieneNadaUtilQuedaVacio(): void
    {
        self::assertSame('', IdentidadTipo::TELEFONO->normalizar('   '));
        self::assertSame('', IdentidadTipo::TELEFONO->normalizar('sin número'));
        self::assertSame('', IdentidadTipo::EMAIL->normalizar('  '));
    }
}
