<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\FileModoEnum;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

/**
 * El freno de la identificación, y sobre todo que SE SUELTE.
 *
 * ⚠️ Un freno que no se suelta no es un freno: es un candado. La versión anterior apoyaba la
 * caducidad en `expiresAfter()` sobre un ítem PSR-6 releído, y Symfony Cache **no restaura la
 * caducidad al leer**: del segundo intento en adelante el contador se guardaba sin caducidad
 * ninguna. Un colegio detrás de un NAT que juntara 8 fallos en días distintos quedaba fuera **de
 * por vida**, con un mensaje diciéndole «vuelve a probar en un rato».
 *
 * A ojo el código parecía correcto. Sólo lo caza dejar pasar la ventana.
 */
final class FrenoDeIntentosTest extends TestCase
{
    private function identidad(ArrayAdapter $cache): IdentidadDelPasajero
    {
        $pila = new RequestStack();
        $req = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        $req->setSession(new Session(new MockArraySessionStorage()));
        $pila->push($req);

        // Un stub sin expectativas: el freno no toca la base. Que no la toque es parte de lo
        // que se prueba — si mañana la tocara, este stub reventaría en vez de pasar callando.
        return new IdentidadDelPasajero(
            $this->createStub(EntityManagerInterface::class),
            $pila,
            $cache,
        );
    }

    private function expediente(): CotizacionFile
    {
        $file = (new CotizacionFile())->setModo(FileModoEnum::GRUPO);

        // `id` no tiene setter: lo asigna Doctrine. La llave de intentos se construye con él, así
        // que sin id todos los expedientes compartirían freno — y eso es justo lo que hay que
        // poder distinguir aquí.
        $ref = new \ReflectionProperty(CotizacionFile::class, 'id');
        $ref->setValue($file, Uuid::v4());

        return $file;
    }

    public function testSeCierraAlOctavoIntento(): void
    {
        $identidad = $this->identidad(new ArrayAdapter());
        $file = $this->expediente();

        for ($i = 1; $i <= 7; $i++) {
            $identidad->identificar($file, 'MAL' . $i, '1900-01-01');
            self::assertFalse($identidad->bloqueado($file), "no debería cerrarse al intento $i");
        }

        $identidad->identificar($file, 'MAL8', '1900-01-01');
        self::assertTrue($identidad->bloqueado($file));
    }

    public function testLaVentanaCADUCA(): void
    {
        $cache = new ArrayAdapter();
        $identidad = $this->identidad($cache);
        $file = $this->expediente();

        for ($i = 1; $i <= 8; $i++) {
            $identidad->identificar($file, 'MAL' . $i, '1900-01-01');
        }

        self::assertTrue($identidad->bloqueado($file));

        // 🔥 El corazón de la prueba: vaciar la caché simula la ventana vencida, y el estado
        // NO puede sobrevivirla. Antes sobrevivía indefinidamente.
        $cache->clear();

        self::assertFalse($identidad->bloqueado($file), 'el freno se quedó pegado: es un candado, no un freno');
    }

    public function testUnAcierto_NO_dejaRastroDeIntentos(): void
    {
        $identidad = $this->identidad(new ArrayAdapter());
        $file = $this->expediente();

        for ($i = 1; $i <= 7; $i++) {
            $identidad->identificar($file, 'MAL' . $i, '1900-01-01');
        }

        self::assertFalse($identidad->bloqueado($file));
    }

    public function testExpedientesDISTINTOSNoSeContagian(): void
    {
        $identidad = $this->identidad(new ArrayAdapter());
        $uno = $this->expediente();
        $otro = $this->expediente();

        for ($i = 1; $i <= 8; $i++) {
            $identidad->identificar($uno, 'MAL' . $i, '1900-01-01');
        }

        // Un curioso en un expediente no puede cerrarle la puerta a otro viaje.
        self::assertTrue($identidad->bloqueado($uno));
        self::assertFalse($identidad->bloqueado($otro));
    }
}
