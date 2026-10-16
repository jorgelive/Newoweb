<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Pms;

use App\Agent\Skill\Pms\ConsultarDisponibilidadSkill;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pedir menos noches que el mínimo NO esconde la casita: se ofrece con su precio, se dice el
 * mínimo y se pregunta si quiere que el equipo vea una excepción. Caso real del 16/10/2026: una
 * noche en Casita 7, mínimo 2.
 */
#[CoversClass(ConsultarDisponibilidadSkill::class)]
final class EstanciaMinimaTest extends TestCase
{
    public function testUnaNocheConMinimoDeDosSeAvisaYSePasaAlEquipo(): void
    {
        $aviso = ConsultarDisponibilidadSkill::avisoEstanciaMinima(1, 2);

        self::assertNotNull($aviso);
        self::assertStringContainsString('Piden 1 noche', $aviso);
        self::assertStringContainsString('estancia mínima de 2 noches', $aviso);
        self::assertStringContainsString('Ofrécela igual', $aviso, 'No es para que no salga');
        self::assertStringContainsString('la limpieza', $aviso, 'El precio se da completo');
        self::assertStringContainsString('Pregúntale si quiere', $aviso, 'Se pregunta antes de escalar');
        self::assertStringContainsString('excepción', $aviso);
        self::assertStringContainsString('escalar_al_equipo', $aviso);
        self::assertStringContainsString('por debajo del mínimo de 2', $aviso, 'El motivo que lee el equipo');
    }

    public function testEnElPluralTambien(): void
    {
        self::assertStringContainsString('Piden 2 noches', (string) ConsultarDisponibilidadSkill::avisoEstanciaMinima(2, 3));
    }

    public function testSinRestriccionNoSeDiceNada(): void
    {
        self::assertNull(ConsultarDisponibilidadSkill::avisoEstanciaMinima(2, 2), 'Justo el mínimo cumple');
        self::assertNull(ConsultarDisponibilidadSkill::avisoEstanciaMinima(5, 2));
        self::assertNull(ConsultarDisponibilidadSkill::avisoEstanciaMinima(1, 1), 'Mínimo de 1 = sin mínimo');
        self::assertNull(ConsultarDisponibilidadSkill::avisoEstanciaMinima(1, 0), 'Sin dato de mínimo');
    }
}
