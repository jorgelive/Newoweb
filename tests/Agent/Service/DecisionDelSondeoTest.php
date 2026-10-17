<?php

declare(strict_types=1);

namespace App\Tests\Agent\Service;

use App\Agent\Service\DecisionDelSondeo as D;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Un aviso por incidente, y otro al recuperarse: nada de un aviso cada 30 minutos.
 */
#[CoversClass(D::class)]
final class DecisionDelSondeoTest extends TestCase
{
    private const string CREDITOS = 'Google AI 402: Your prepayment credits are depleted.';

    public function testSinCreditosSeAvisaALaPrimera(): void
    {
        self::assertSame(D::AVISAR_CAIDA, D::tras(self::CREDITOS, 0, false, false)['accion']);
    }

    public function testUnCorteSueltoNoAvisaYElSegundoSi(): void
    {
        $primero = D::tras('Google AI 503: overloaded', 0, false, false);
        self::assertSame(D::NADA, $primero['accion']);

        self::assertSame(D::AVISAR_CAIDA, D::tras('Google AI 503: overloaded', $primero['fallos'], false, false)['accion']);
    }

    public function testYaAvisadoNoSeRepite(): void
    {
        self::assertSame(D::NADA, D::tras(self::CREDITOS, 5, true, false)['accion']);
    }

    public function testAlRecuperarseSeAvisaUnaVezYSeReiniciaLaCuenta(): void
    {
        $vuelta = D::tras(null, 7, true, false);
        self::assertSame(D::AVISAR_RECUPERACION, $vuelta['accion']);
        self::assertSame(0, $vuelta['fallos']);

        self::assertSame(D::NADA, D::tras(null, 0, false, false)['accion'], 'Sin aviso previo no hay recuperación que contar.');
    }

    public function testSiAvisoUnTurnoDeHuespedTambienSeCuentaLaRecuperacion(): void
    {
        self::assertSame(D::AVISAR_RECUPERACION, D::tras(null, 0, false, true)['accion']);
    }

    public function testLaPistaDiceQueHacer(): void
    {
        self::assertStringContainsString('recargar', D::pista('google', self::CREDITOS));
        self::assertStringContainsString('clave', D::pista('google', 'Google AI 403: PERMISSION_DENIED'));
        self::assertSame('', D::pista('google', 'timeout'));
    }
}
