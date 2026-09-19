<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Cotizacion\Documento\GiradorDeEscaneo;
use App\Cotizacion\Documento\Orientacion;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Girar un escaneo que nunca se leyó no puede dejarlo mudo.
 *
 * 🔥 Es el fallo de los 32 reversos: sin lectura, `conOrientacionCorregida()` devuelve `null`, y
 * `registrarLectura(null)` significa «se intentó y falló» —no «no se ha leído»—, así que el archivo
 * no volvía a leerse nunca y su aviso de «está torcido» no podía volver a salir. Ver
 * `docs/Cotizaciones.md` y `Version20260919040000`.
 */
#[CoversClass(GiradorDeEscaneo::class)]
final class GiroSinLecturaTest extends TestCase
{
    /**
     * El contrato de la entidad que hace peligroso el descuido: los dos dejan la lectura vacía y
     * **no significan lo mismo**.
     */
    public function testRegistrarNuloNoEsOlvidar(): void
    {
        $archivo = new CotizacionFilearchivo();
        self::assertFalse($archivo->seIntentoLeer(), 'recién creado no se ha intentado leer');

        $archivo->registrarLectura(null);
        self::assertNull($archivo->getDatosLeidos());
        self::assertTrue($archivo->seIntentoLeer(), 'registrarLectura(null) marca «intentado y fallido»');

        $archivo->olvidarLectura();
        self::assertFalse($archivo->seIntentoLeer(), 'olvidarLectura() sí lo devuelve a «nunca leído»');
    }

    /** Por eso el girador tiene que preguntar antes: sin lectura, no hay nada que corregir. */
    public function testSinLecturaNoHayOrientacionQueCorregir(): void
    {
        $metodo = new ReflectionMethod(GiradorDeEscaneo::class, 'conOrientacionCorregida');

        self::assertNull($metodo->invoke(null, null, 90));
    }

    /**
     * Y con lectura, corregir es restar lo girado: un escaneo con la cabecera a la derecha (le
     * faltan 270°) al que se le aplican 270° queda derecho.
     */
    public function testConLecturaSeDescuentaLoQueSeAcabaDeGirar(): void
    {
        $metodo = new ReflectionMethod(GiradorDeEscaneo::class, 'conOrientacionCorregida');

        /** @var array<string, mixed> $corregido */
        $corregido = $metodo->invoke(null, ['bordeSuperior' => 'derecha'], 270);

        self::assertSame('arriba', $corregido['bordeSuperior']);
        self::assertSame(0, Orientacion::grados('arriba'));
    }
}
