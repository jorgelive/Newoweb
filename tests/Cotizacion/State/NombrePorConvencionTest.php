<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\State\CotizacionFilearchivoMultipartProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * El nombre que se le pone solo a un escaneo de identidad.
 *
 * ── Por qué existe este test ────────────────────────────────────────────────
 * Al escribir `nombrarSiEsDeIdentidad()` se descubrió que `CotizacionFilearchivo::getNombre()`
 * devolvía `null` sobre una firma `array` — un `TypeError` en el caso EXACTO que esta función
 * viene a atender: el archivo que se sube sin nombre. No lo veía PHPStan (no marca ese
 * `return.type` dentro de `Entity/`) ni ningún test, porque nadie preguntaba por el nombre de un
 * archivo recién subido.
 *
 * Así que aquí se fija justamente eso: que el camino del archivo SIN nombre funcione.
 */
final class NombrePorConvencionTest extends TestCase
{
    private function procesar(CotizacionFilearchivo $archivo): CotizacionFilearchivo
    {
        // El persistidor de Doctrine devuelve lo que le llega: aquí sólo interesa qué se le pasa.
        $persistidor = new class implements ProcessorInterface {
            /** @param array<string, mixed> $uriVariables @param array<string, mixed> $context */
            public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
            {
                return $data;
            }
        };

        $procesador = new CotizacionFilearchivoMultipartProcessor($persistidor, new RequestStack());
        $salida = $procesador->process($archivo, new Post());

        self::assertInstanceOf(CotizacionFilearchivo::class, $salida);

        return $salida;
    }

    #[Test]
    #[DataProvider('escaneosDeIdentidad')]
    public function un_escaneo_de_identidad_sin_nombre_se_nombra_solo(ArchivoTipoEnum $tipo, string $esperado): void
    {
        $archivo = (new CotizacionFilearchivo())->setTipoArchivo($tipo);

        // Sin tocar `setNombre()`: la columna se queda en `null`, que es como llega del formulario
        // cuando el operador no escribe nada. Éste es el caso que reventaba.
        $salida = $this->procesar($archivo);

        self::assertSame([['language' => 'es', 'content' => $esperado]], $salida->getNombre());
    }

    /** @return iterable<string, array{ArchivoTipoEnum, string}> */
    public static function escaneosDeIdentidad(): iterable
    {
        yield 'pasaporte' => [ArchivoTipoEnum::PASAPORTE, 'Pasaporte (escaneo)'];
        yield 'dni anverso' => [ArchivoTipoEnum::DNI_ANVERSO, 'DNI — anverso'];
        // El reverso y la autorización NO respaldan ningún número, pero sí son de identidad: es la
        // diferencia entre `esEscaneoDeIdentidad()` y `respaldaA()`, y confundirlas dejaría estos
        // dos pidiendo nombre.
        yield 'dni reverso' => [ArchivoTipoEnum::DNI_REVERSO, 'DNI — reverso'];
        yield 'autorización' => [ArchivoTipoEnum::AUTORIZACION, 'Autorización notarial'];
    }

    #[Test]
    public function lo_que_el_operador_escribio_manda(): void
    {
        $archivo = (new CotizacionFilearchivo())
            ->setTipoArchivo(ArchivoTipoEnum::PASAPORTE);
        $archivo->setNombre([['language' => 'es', 'content' => 'Pasaporte de la madre']]);

        self::assertSame(
            [['language' => 'es', 'content' => 'Pasaporte de la madre']],
            $this->procesar($archivo)->getNombre(),
            'La convención es un defecto, no una regla que pise lo que alguien decidió.',
        );
    }

    /**
     * Un nombre en blanco cuenta como vacío.
     *
     * El formulario manda la clave sólo si hay texto, pero un cliente de la API puede mandar la
     * fila con espacios. Un archivo llamado « » se ve como una fila en blanco en la bóveda.
     */
    #[Test]
    public function un_nombre_solo_de_espacios_no_cuenta(): void
    {
        $archivo = (new CotizacionFilearchivo())->setTipoArchivo(ArchivoTipoEnum::PASAPORTE);
        $archivo->setNombre([['language' => 'es', 'content' => '   ']]);

        self::assertSame(
            [['language' => 'es', 'content' => 'Pasaporte (escaneo)']],
            $this->procesar($archivo)->getNombre(),
        );
    }

    #[Test]
    public function a_un_boleto_no_se_le_inventa_nombre(): void
    {
        $archivo = (new CotizacionFilearchivo())->setTipoArchivo(ArchivoTipoEnum::BOLETO);

        self::assertSame(
            [],
            $this->procesar($archivo)->getNombre(),
            'Un boleto SÍ necesita que alguien lo nombre: el tipo no distingue una entrada de otra.',
        );
    }

    /** Sin tipo no hay convención que aplicar, y no puede reventar por ello. */
    #[Test]
    public function sin_tipo_no_pasa_nada(): void
    {
        self::assertSame([], $this->procesar(new CotizacionFilearchivo())->getNombre());
    }
}
