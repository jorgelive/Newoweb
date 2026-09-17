<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\EventListener;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\EventListener\EscaneoNuevoInvalidaVeredictoListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;

/**
 * La señal de «viene un fichero nuevo», que decide si se tira la lectura de un archivo.
 *
 * 🔥 **Este test probaba antes una función que no podía funcionar, y pasaba en verde.** Miraba
 * `isset($cambios['imageName'])` sobre un changeset construido a mano — y en producción, en el
 * instante en que corre este listener, **`imageName` nunca está en el changeset**: lo escribe Vich
 * en `preUpdate`, y el ORM despacha `onFlush` antes de `executeUpdates()`. El test verde defendía
 * una rama muerta: reemplazar el PDF por el bueno dejaba la lectura del viejo para siempre.
 *
 * ⚠️ La lección: **un test sobre una función pura sólo vale lo que valga su entrada.** Si la entrada
 * se inventa, se prueba la aritmética y no el hecho. Por eso ahora se le pasa la entidad, que es lo
 * que el listener recibe de verdad.
 */
final class InvalidaLoGuardadoTest extends TestCase
{
    /** Lo que Vich deja puesto al subir: `imageFile`, que en `onFlush` todavía no ha consumido. */
    public function testUnFicheroPendienteEsFicheroNuevo(): void
    {
        // `setImageFile()` no devuelve `$this` —toca `updatedAt` para forzar el UPDATE— así que no
        // encadena. Es lo que Vich deja puesto al subir y lo que este listener puede ver.
        $archivo = new CotizacionFilearchivo();
        $archivo->setImageFile(new File(__FILE__));

        self::assertTrue(EscaneoNuevoInvalidaVeredictoListener::hayFicheroNuevo($archivo));
    }

    /**
     * 🔥 **Guardar la lectura NO es un fichero nuevo**, y es el caso que no puede fallar: el control
     * escribe `datosLeidos` y flushea en el acto, así que este método corre en el mismo `flush` que
     * guarda la lectura recién pagada. Un `true` de más la borraría sin error y sin rastro.
     */
    public function testGuardarLaLecturaNoEsFicheroNuevo(): void
    {
        $archivo = (new CotizacionFilearchivo())->registrarLectura(['esEticket' => true]);

        self::assertFalse(EscaneoNuevoInvalidaVeredictoListener::hayFicheroNuevo($archivo));
    }

    /** Ni escribir el veredicto, ni renombrar, ni nada que no traiga bytes. */
    public function testUnArchivoSinSubidaPendienteNoLoEs(): void
    {
        self::assertFalse(EscaneoNuevoInvalidaVeredictoListener::hayFicheroNuevo(new CotizacionFilearchivo()));
    }

    /**
     * 🔥 **Girar un pasaporte le borraba la firma a quien lo había mirado.** El changeset es el que
     * deja `GiradorDeEscaneo`, que no pasa por Vich. Se verifica además con el flujo real: un
     * changeset escrito a mano ya engañó a este archivo una vez.
     */
    public function testGirarNoCaducaNada(): void
    {
        $caduca = EscaneoNuevoInvalidaVeredictoListener::queCaduca(false, false, [
            'rotacionAplicada' => [0, 90],
            'datosLeidos' => [['numero' => 'X'], ['numero' => 'X']],
            'imageSize' => [400000, 410000],
            'updatedAt' => [null, null],
        ]);

        self::assertSame(['veredicto' => false, 'lectura' => false], $caduca);
    }

    public function testRenombrarNoCaducaNada(): void
    {
        self::assertSame(
            ['veredicto' => false, 'lectura' => false],
            EscaneoNuevoInvalidaVeredictoListener::queCaduca(false, false, ['nombre' => [[], []]]),
        );
    }

    public function testUnFicheroNuevoCaducaLasDos(): void
    {
        self::assertSame(
            ['veredicto' => true, 'lectura' => true],
            EscaneoNuevoInvalidaVeredictoListener::queCaduca(false, true, ['updatedAt' => [null, null]]),
        );
    }

    /** Los bytes son los mismos: tirar la lectura costaría otra llamada pagada para nada. */
    public function testReasignarCaducaElVeredictoPeroNoLaLectura(): void
    {
        self::assertSame(
            ['veredicto' => true, 'lectura' => false],
            EscaneoNuevoInvalidaVeredictoListener::queCaduca(false, false, ['pasajero' => ['viejo', 'nuevo']]),
        );
    }

    public function testCambiarElTipoCaducaLasDos(): void
    {
        self::assertSame(
            ['veredicto' => true, 'lectura' => true],
            EscaneoNuevoInvalidaVeredictoListener::queCaduca(false, false, ['tipoArchivo' => ['dni_anverso', 'pasaporte']]),
        );
    }

    /** ⚠️ En un alta el changeset trae TODOS los campos: el tipo no puede contar como cambio. */
    public function testUnAltaSinFicheroNoTiraSuLectura(): void
    {
        self::assertSame(
            ['veredicto' => true, 'lectura' => false],
            EscaneoNuevoInvalidaVeredictoListener::queCaduca(true, false, ['tipoArchivo' => [null, 'pasaporte'], 'pasajero' => [null, 'x']]),
        );
    }
}
