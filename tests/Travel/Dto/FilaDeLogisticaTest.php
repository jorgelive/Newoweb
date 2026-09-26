<?php

declare(strict_types=1);

namespace App\Tests\Travel\Dto;

use App\Travel\Dto\FilaDeLogistica;
use App\Travel\Enum\ComponenteModoEnum;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FilaDeLogisticaTest extends TestCase
{
    private const string COMPONENTE = '0198f0a2-1c2d-7e3f-8a4b-5c6d7e8f9a0b';

    /** Lo que manda de verdad el modal del panel: números como texto, horas «HH:MM». */
    public function testLeeLaFilaQueMandaElPanel(): void
    {
        [$fila] = FilaDeLogistica::lista([[
            'componenteId' => self::COMPONENTE,
            'tarifaId' => '',
            'dia' => '2',
            'hora' => '08:30',
            'horaFin' => '',
            'modo' => 'cortesia',
            'orden' => '3',
            'servicioCompleto' => true,
        ]]);

        self::assertSame(self::COMPONENTE, $fila->componenteId->toRfc4122());
        self::assertNull($fila->tarifaId);
        self::assertSame(2, $fila->dia);
        self::assertSame('08:30', $fila->hora?->format('H:i'));
        self::assertNull($fila->horaFin);
        self::assertSame(ComponenteModoEnum::CORTESIA, $fila->modo);
        self::assertSame(3, $fila->orden);
        self::assertTrue($fila->servicioCompleto);
    }

    public function testUnaFilaSinComponenteSeIgnoraComoAntes(): void
    {
        self::assertSame([], FilaDeLogistica::lista([['componenteId' => '', 'orden' => 1]]));
        self::assertSame([], FilaDeLogistica::lista([]));
    }

    public function testUnModoDesconocidoEsIncluidoYElDiaVacioEsNulo(): void
    {
        [$fila] = FilaDeLogistica::lista([['componenteId' => self::COMPONENTE, 'modo' => 'raro', 'dia' => null]]);

        self::assertSame(ComponenteModoEnum::INCLUIDO, $fila->modo);
        self::assertNull($fila->dia);
        self::assertSame(0, $fila->orden);
    }

    public function testUnFalseEnTextoNoEsServicioCompleto(): void
    {
        [$fila] = FilaDeLogistica::lista([['componenteId' => self::COMPONENTE, 'servicioCompleto' => 'false']]);

        self::assertFalse($fila->servicioCompleto);
    }

    /**
     * Antes esto era un 500 DESPUÉS de haber borrado la logística vieja. Ahora falla antes de
     * que el controlador toque nada, diciendo qué fila.
     */
    public function testUnaHoraIlegibleFallaDiciendoLaFila(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Fila 2');

        FilaDeLogistica::lista([
            ['componenteId' => self::COMPONENTE],
            ['componenteId' => self::COMPONENTE, 'hora' => 'a las ocho'],
        ]);
    }

    public function testUnaHoraQueNoEsTextoNoSeConvierteEnAhora(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilaDeLogistica::lista([['componenteId' => self::COMPONENTE, 'hora' => ['08:30']]]);
    }

    public function testUnaTarifaMalFormadaFalla(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tarifa');

        FilaDeLogistica::lista([['componenteId' => self::COMPONENTE, 'tarifaId' => 'no-es-un-id']]);
    }

    public function testUnCuerpoQueNoEsUnaListaNoPasa(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilaDeLogistica::lista(null);
    }
}
