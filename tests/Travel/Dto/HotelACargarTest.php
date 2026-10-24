<?php

declare(strict_types=1);

namespace App\Tests\Travel\Dto;

use App\Travel\Dto\HotelACargar;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HotelACargarTest extends TestCase
{
    public function testLeeLaFichaYAñadeElNombreALosQueSeBuscan(): void
    {
        $hotel = HotelACargar::fromArray([
            'buscar' => ['Hotel Viejo'],
            'nombre' => ' Hotel Nuevo ',
            'titulo' => 'Hotel Nuevo',
            'descripcion' => 'Un hotel.',
            'email' => '',
            'fotos' => ['a.jpg', 3, null],
            'habitaciones' => [['nombre' => 'Doble', 'descripcion' => 'Dos camas.', 'fotos' => ['b.jpg']], 'basura'],
        ]);

        self::assertSame(['Hotel Viejo', 'Hotel Nuevo'], $hotel->buscar);
        self::assertSame('Hotel Nuevo', $hotel->nombre);
        self::assertNull($hotel->email, 'un correo vacío no es un correo');
        self::assertSame(['a.jpg', '3'], $hotel->fotos);
        self::assertSame([['nombre' => 'Doble', 'descripcion' => 'Dos camas.', 'fotos' => ['b.jpg']]], $hotel->habitaciones);
    }

    public function testUnCampoObligatorioVacioSeDiceConSuNombre(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('habitaciones[0].descripcion');

        HotelACargar::fromArray([
            'nombre' => 'H', 'titulo' => 'H', 'descripcion' => 'd',
            'habitaciones' => [['nombre' => 'Doble', 'descripcion' => '  ']],
        ]);
    }
}
