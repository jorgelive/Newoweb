<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionFilepasajero;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Primer apellido y el resto, con las partículas pegadas al apellido que las sigue.
 *
 * Partía por el primer espacio: «de la Cruz Cruz» daba «de». Lo usa la lista de Cultura.
 */
final class ApellidosPorSeparadoTest extends TestCase
{
    /** @return iterable<string, array{string|null, string|null, string|null}> */
    public static function casos(): iterable
    {
        yield 'dos simples' => ['Valverde Ríos', 'Valverde', 'Ríos'];
        yield 'compuesto delante' => ['de la Cruz Cruz', 'de la Cruz', 'Cruz'];
        yield 'compuesto detrás' => ['Santos de Blas', 'Santos', 'de Blas'];
        yield 'uno solo' => ['Smith', 'Smith', null];
        yield 'tres' => ['Gómez Valencia Pérez', 'Gómez', 'Valencia Pérez'];
        yield 'mayúsculas' => ['DEL CASTILLO SOTO', 'DEL CASTILLO', 'SOTO'];
        yield 'espacios de más' => ['  Quispe   Mamani ', 'Quispe', 'Mamani'];
        // La «y» une dos apellidos y no es de ninguno: no se pega al segundo.
        yield 'con y' => ['García y López', 'García', 'López'];
        yield 'compuesto con y' => ['de la Cruz y Pérez', 'de la Cruz', 'Pérez'];
        yield 'vacío' => ['', null, null];
        yield 'nulo' => [null, null, null];
    }

    #[Test]
    #[DataProvider('casos')]
    public function separa(?string $apellido, ?string $paterno, ?string $materno): void
    {
        self::assertSame([$paterno, $materno], CotizacionFilepasajero::apellidosPorSeparado($apellido));
    }
}
