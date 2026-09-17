<?php

declare(strict_types=1);

namespace App\Tests\Message\Factory;

use App\Contract\ConversationMilestoneInterface;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Factory\MessageConversationFactory;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Una reserva cancelada no cierra el hilo mientras cuelgue de él otra reserva viva.
 *
 * El caso que lo destapó: Vanessa cancela 5GEFZ9 y reserva 2KRERH, las dos en el mismo hilo.
 * Cada sincronización recalcula las dos y el hilo quedaba como lo dejara la última — abierto con
 * un recordatorio nuevo, o cerrado y con el recordatorio de 2KRERH cancelado. Copias vivas o
 * silencio, según el orden del lote.
 */
final class CierreDeHiloConAsuntosVivosTest extends TestCase
{
    #[Test]
    public function cancelar_una_reserva_no_cierra_el_hilo_si_hay_otra_viva(): void
    {
        self::assertTrue($this->quedaOtroVivo([
            $this->enlace('5GEFZ9', VinculoComercial::Terminado, cancelada: true),
            $this->enlace('2KRERH', VinculoComercial::Cliente),
        ], cancela: '5GEFZ9'));
    }

    #[Test]
    public function con_la_unica_reserva_cancelada_el_hilo_se_cierra_como_siempre(): void
    {
        // El caso de toda la vida —un hilo, un asunto— no cambia: cancelar lo cierra.
        self::assertFalse($this->quedaOtroVivo([
            $this->enlace('5GEFZ9', VinculoComercial::Terminado, cancelada: true),
        ], cancela: '5GEFZ9'));
    }

    #[Test]
    public function el_propio_asunto_no_cuenta_aunque_su_enlace_siga_vivo(): void
    {
        // El enlace del asunto que se cancela se sincroniza DESPUÉS de decidir el cierre, así que
        // todavía puede decir `cliente`. Si contara, ninguna cancelación cerraría nunca un hilo.
        self::assertFalse($this->quedaOtroVivo([
            $this->enlace('5GEFZ9', VinculoComercial::Cliente),
        ], cancela: '5GEFZ9'));
    }

    #[Test]
    public function otras_reservas_muertas_no_mantienen_el_hilo_abierto(): void
    {
        self::assertFalse($this->quedaOtroVivo([
            $this->enlace('5GEFZ9', VinculoComercial::Terminado, cancelada: true),
            $this->enlace('AAAAAA', VinculoComercial::Terminado),
            $this->enlace('BBBBBB', VinculoComercial::Cliente, cancelada: true),
        ], cancela: '5GEFZ9'));
    }

    #[Test]
    public function el_acompanante_no_mantiene_abierto_un_hilo(): void
    {
        // No programa nada: el motor sólo arma agendas de titulares.
        self::assertFalse($this->quedaOtroVivo([
            $this->enlace('5GEFZ9', VinculoComercial::Terminado, cancelada: true),
            $this->enlace('2KRERH', VinculoComercial::Cliente, titular: false),
        ], cancela: '5GEFZ9'));
    }

    #[Test]
    public function un_asunto_ilegible_cuenta_como_vivo(): void
    {
        // Cerrar de más silencia a los vivos sin avisar; dejar abierto no envía nada a un muerto.
        $roto = $this->createStub(ConversacionEnlaceInterface::class);
        $roto->method('esTitular')->willReturn(true);
        $roto->method('getContextType')->willReturn('pms_reserva');
        $roto->method('getContextId')->willReturn('ROTO');
        $roto->method('getMilestones')->willThrowException(new RuntimeException('proxy sin fila'));

        self::assertTrue($this->quedaOtroVivo([
            $this->enlace('5GEFZ9', VinculoComercial::Terminado, cancelada: true),
            $roto,
        ], cancela: '5GEFZ9'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function enlace(
        string $id,
        VinculoComercial $vinculo,
        bool $cancelada = false,
        bool $titular = true,
    ): ConversacionEnlaceInterface {
        $enlace = $this->createStub(ConversacionEnlaceInterface::class);
        $enlace->method('esTitular')->willReturn($titular);
        $enlace->method('getContextType')->willReturn('pms_reserva');
        $enlace->method('getContextId')->willReturn($id);
        $enlace->method('getVinculo')->willReturn($vinculo);
        $enlace->method('getMilestones')->willReturn(
            $cancelada ? [ConversationMilestoneInterface::CANCELLED => '2026-08-25T12:58:03'] : []
        );

        return $enlace;
    }

    /**
     * @param list<ConversacionEnlaceInterface> $enlaces
     */
    private function quedaOtroVivo(array $enlaces, string $cancela): bool
    {
        $proveedor = $this->createStub(ProveedorDeEnlacesInterface::class);
        $proveedor->method('paraConversacion')->willReturn($enlaces);

        $em = $this->createStub(EntityManagerInterface::class);

        $factory = new MessageConversationFactory(
            $em,
            new ResolutorDeHilo($em, new NullLogger()),
            new EnlacesDeConversacion([$proveedor]),
            new NullLogger(),
        );

        return (bool) new ReflectionMethod(MessageConversationFactory::class, 'quedaOtroAsuntoVivo')
            ->invoke($factory, new MessageConversation('pms_reserva', $cancela), 'pms_reserva', $cancela);
    }
}
