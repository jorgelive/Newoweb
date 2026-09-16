<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\EventListener;

use App\Cotizacion\EventListener\EscaneoNuevoInvalidaVeredictoListener;
use PHPUnit\Framework\TestCase;

/**
 * La guarda que decide si se tira la lectura de un archivo.
 *
 * 🔥 **Corre en el MISMO `flush` que guarda la lectura recién pagada.** El control escribe
 * `datosLeidos` y flushea en el acto —una lectura es dinero gastado y no puede esperar al final—,
 * así que un `true` de más aquí borra lo que se acaba de comprar, sin error y sin rastro. De ahí
 * que la decisión esté separada del listener: para poder exigírsela sin montar un `UnitOfWork`.
 */
final class InvalidaLoGuardadoTest extends TestCase
{
    /** 🔥 El caso que no puede fallar: guardar la lectura NO puede tirar la lectura. */
    public function testGuardarLaLecturaNoLaInvalida(): void
    {
        self::assertFalse(EscaneoNuevoInvalidaVeredictoListener::invalidaLoGuardado([
            'datosLeidos' => [null, ['esEticket' => true]],
            'leidoEn' => [null, new \DateTimeImmutable()],
        ]));
    }

    /** Ni escribir el veredicto. */
    public function testGuardarElVeredictoTampoco(): void
    {
        self::assertFalse(EscaneoNuevoInvalidaVeredictoListener::invalidaLoGuardado([
            'estadoValidacion' => ['no_validado', 'observado'],
            'discrepancias' => [[], [['campo' => 'nombre']]],
            'notasValidacion' => [[], ['ojo']],
            'validadoEn' => [null, new \DateTimeImmutable()],
        ]));
    }

    /** Reasignar el archivo a otra persona: el veredicto se calculó contra alguien que ya no es. */
    public function testCambiarDeDuenyoSiInvalida(): void
    {
        self::assertTrue(EscaneoNuevoInvalidaVeredictoListener::invalidaLoGuardado([
            'pasajero' => [null, 'otro'],
        ]));
    }

    /** Reemplazar el fichero: la lectura cacheada es la del documento viejo. */
    public function testCambiarElFicheroSiInvalida(): void
    {
        self::assertTrue(EscaneoNuevoInvalidaVeredictoListener::invalidaLoGuardado([
            'imageName' => ['viejo.pdf', 'nuevo.pdf'],
        ]));
    }

    /** ⚠️ `updatedAt` se mueve por cualquier cosa, incluida la propia escritura de la lectura. */
    public function testUpdatedAtNoBastaParaInvalidar(): void
    {
        self::assertFalse(EscaneoNuevoInvalidaVeredictoListener::invalidaLoGuardado([
            'updatedAt' => [new \DateTimeImmutable('-1 day'), new \DateTimeImmutable()],
        ]));
    }

    /** Renombrar el documento o girarlo no cambia lo que dice. */
    public function testOtrosCamposNoInvalidan(): void
    {
        self::assertFalse(EscaneoNuevoInvalidaVeredictoListener::invalidaLoGuardado([
            'nombre' => [null, [['language' => 'es', 'content' => 'Pasaporte']]],
            'rotacionAplicada' => [0, 90],
            'tipoArchivo' => ['otros', 'eticket'],
        ]));
    }
}
