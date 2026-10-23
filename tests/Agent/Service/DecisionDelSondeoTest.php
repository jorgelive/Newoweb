<?php

declare(strict_types=1);

namespace App\Tests\Agent\Service;

use App\Agent\Conversation\FalloDelMotor;
use App\Agent\Service\DecisionDelSondeo as D;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Un aviso por incidente, y otro al recuperarse: nada de un aviso cada 30 minutos.
 */
#[CoversClass(D::class)]
final class DecisionDelSondeoTest extends TestCase
{
    private static function creditos(): FalloDelMotor
    {
        return FalloDelMotor::porCodigoHttp('Google AI 402: Your prepayment credits are depleted.', 402);
    }

    private static function caidaPasajera(): FalloDelMotor
    {
        return FalloDelMotor::porCodigoHttp('Google AI 503: overloaded', 503);
    }

    public function testSinCreditosSeAvisaALaPrimera(): void
    {
        self::assertSame(D::AVISAR_CAIDA, D::tras(self::creditos(), 0, false, false)['accion']);
    }

    public function testUnCorteSueltoNoAvisaYElSegundoSi(): void
    {
        $primero = D::tras(self::caidaPasajera(), 0, false, false);
        self::assertSame(D::NADA, $primero['accion']);

        self::assertSame(D::AVISAR_CAIDA, D::tras(self::caidaPasajera(), $primero['fallos'], false, false)['accion']);
    }

    public function testYaAvisadoNoSeRepite(): void
    {
        self::assertSame(D::NADA, D::tras(self::creditos(), 5, true, false)['accion']);
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
        self::assertStringContainsString('recargar', D::pista('google', self::creditos()));
        self::assertStringContainsString('clave', D::pista('google', FalloDelMotor::porCodigoHttp('Google AI 403: PERMISSION_DENIED', 403)));
        self::assertSame('', D::pista('google', new FalloDelMotor('timeout')));
    }

    public function testEnUnaRondaCadaMotorVaPorSuCuenta(): void
    {
        $ronda = D::deLaRonda(['anthropic' => null, 'google' => self::creditos()], [], [], false);

        self::assertSame(D::NADA, $ronda['motores']['anthropic']['accion']);
        self::assertSame(D::AVISAR_CAIDA, $ronda['motores']['google']['accion']);
        self::assertFalse($ronda['todosResponden']);
    }

    public function testUnMotorSanoNoAnunciaLaRecuperacionDelAvisoDeUnTurno(): void
    {
        // Un turno de huésped avisó (no se sabe de qué motor) y Gemini sigue caído: callar.
        $ronda = D::deLaRonda(['anthropic' => null, 'google' => self::creditos()], [], ['google' => true], true);

        self::assertSame(D::NADA, $ronda['motores']['anthropic']['accion']);
        self::assertFalse($ronda['recuperarTurno']);
    }

    public function testElAvisoDeTurnoSeResuelveCuandoRespondenTodos(): void
    {
        $ronda = D::deLaRonda(['anthropic' => null, 'google' => null], [], [], true);

        self::assertTrue($ronda['todosResponden']);
        self::assertTrue($ronda['recuperarTurno']);
    }

    public function testSiYaSeAnuncioLaVueltaDeUnMotorNoSeRepiteLaDelTurno(): void
    {
        $ronda = D::deLaRonda(['anthropic' => null, 'google' => null], ['google' => 3], ['google' => true], true);

        self::assertSame(D::AVISAR_RECUPERACION, $ronda['motores']['google']['accion']);
        self::assertFalse($ronda['recuperarTurno']);
    }
}
