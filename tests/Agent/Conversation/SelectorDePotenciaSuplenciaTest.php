<?php

declare(strict_types=1);

namespace App\Tests\Agent\Conversation;

use App\Agent\Conversation\AgentEngineInterface;
use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\MotoresFueraDeServicio;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Un motor sin créditos no deja al huésped con el acuse si otro de los tramos puede contestar.
 * La configuración es la de producción: Anthropic por defecto en alta y media, Gemini en baja.
 */
#[CoversClass(SelectorDePotencia::class)]
#[CoversClass(MotoresFueraDeServicio::class)]
final class SelectorDePotenciaSuplenciaTest extends TestCase
{
    private MotoresFueraDeServicio $fuera;

    private SelectorDePotencia $selector;

    protected function setUp(): void
    {
        $this->fuera = new MotoresFueraDeServicio(new ArrayAdapter());
        $registro = new AgentEngineRegistry([$this->motor('anthropic'), $this->motor('google'), $this->motor('deepseek')], 'anthropic');
        $this->selector = new SelectorDePotencia($registro, new NullLogger(), $this->fuera, 'anthropic:', 'anthropic:', 'google:');
    }

    public function testSinCaidasCadaTramoVaASuMotor(): void
    {
        self::assertSame('anthropic', $this->selector->elegir(PotenciaRequerida::Alta)?->motor->nombre());
        self::assertSame('google', $this->selector->elegir(PotenciaRequerida::Baja)?->motor->nombre());
    }

    public function testSiCaeElDelTramoBajoLoCubreElPorDefecto(): void
    {
        $this->fuera->sacar('google', '402');

        self::assertSame('anthropic', $this->selector->elegir(PotenciaRequerida::Baja)?->motor->nombre());
    }

    public function testSiCaeElPorDefectoLoCubreElDeOtroTramo(): void
    {
        $this->fuera->sacar('anthropic', '402');

        self::assertSame('google', $this->selector->elegir(PotenciaRequerida::Alta)?->motor->nombre());
        self::assertNull($this->selector->elegir(PotenciaRequerida::Alta)?->modelo, 'con el modelo del suplente, no el del tramo');
    }

    public function testSiCaenLosDeLosTramosCubreElQueTieneClaveSinTramo(): void
    {
        $this->fuera->sacar('anthropic', '402');
        $this->fuera->sacar('google', '402');

        self::assertSame('deepseek', $this->selector->elegir(PotenciaRequerida::Alta)?->motor->nombre());
        self::assertSame('deepseek', $this->selector->elegir(PotenciaRequerida::Baja)?->motor->nombre());
    }

    public function testSiCaenTodosSigueElSuyoYNoSeInventaOtro(): void
    {
        $this->fuera->sacar('anthropic', '402');
        $this->fuera->sacar('google', '402');
        $this->fuera->sacar('deepseek', '402');

        self::assertSame('anthropic', $this->selector->elegir(PotenciaRequerida::Alta)?->motor->nombre());
    }

    public function testElSondeoSigueViendoLosMotoresConfiguradosAunqueEstenFuera(): void
    {
        $this->fuera->sacar('google', '402');

        $nombres = array_map(static fn (AgentEngineInterface $m): string => $m->nombre(), $this->selector->motoresEnUso());

        self::assertSame(['anthropic', 'google'], $nombres);
    }

    public function testAlDevolverloVuelveASuTramo(): void
    {
        $this->fuera->sacar('google', '402');
        self::assertTrue($this->fuera->devolver('google'));

        self::assertSame('google', $this->selector->elegir(PotenciaRequerida::Baja)?->motor->nombre());
    }

    private function motor(string $nombre): AgentEngineInterface
    {
        $motor = $this->createStub(AgentEngineInterface::class);
        $motor->method('nombre')->willReturn($nombre);
        $motor->method('estaDisponible')->willReturn(true);
        $motor->method('modelos')->willReturn(['m']);

        return $motor;
    }
}
