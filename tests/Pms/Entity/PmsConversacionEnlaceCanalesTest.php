<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El chat de la OTA es del titular. Un acompañante no se alcanza por ahí: con la cabecera de su
 * hilo apuntando a la reserva, un mensaje a Carla habría aterrizado en la bandeja de Booking de
 * Bruna. El dominio dice cuál es ese canal; el núcleo se lo quita a quien no es titular.
 */
#[CoversClass(PmsConversacionEnlace::class)]
#[CoversClass(EnlacesDeConversacion::class)]
final class PmsConversacionEnlaceCanalesTest extends TestCase
{
    public function testElChatDeLaReservaEsDelAsunto(): void
    {
        $enlace = new PmsConversacionEnlace();

        self::assertSame([], $enlace->canalesPosibles());
        self::assertSame(['beds24'], $enlace->canalesDelAsunto());
    }

    public function testAlAcompananteSeLeQuitaElChatDelTitular(): void
    {
        $hilo = new MessageConversation('pms_reserva', 'r-1');
        $enlace = (new PmsConversacionEnlace($hilo))->setEsTitular(false);

        self::assertSame(['beds24'], $this->enlaces([$enlace])->canalesVetados($hilo));
    }

    public function testAlTitularNoSeLeQuitaNada(): void
    {
        $hilo = new MessageConversation('pms_reserva', 'r-1');

        self::assertSame([], $this->enlaces([new PmsConversacionEnlace($hilo)])->canalesVetados($hilo));
    }

    public function testConUnaReservaSuyaAlLadoNoSeQuitaACiegas(): void
    {
        $hilo = new MessageConversation('pms_reserva', 'r-1');
        $acompanante = (new PmsConversacionEnlace($hilo))->setEsTitular(false);
        $suya = new PmsConversacionEnlace($hilo);

        self::assertSame([], $this->enlaces([$acompanante, $suya])->canalesVetados($hilo));
    }

    /** @param list<PmsConversacionEnlace> $lista */
    private function enlaces(array $lista): EnlacesDeConversacion
    {
        return new EnlacesDeConversacion([new class ($lista) implements ProveedorDeEnlacesInterface {
            /** @param list<PmsConversacionEnlace> $lista */
            public function __construct(private readonly array $lista) {}
            public function getNegocio(): string { return 'alojamiento'; }
            /** @return list<ConversacionEnlaceInterface> */
            public function paraConversacion(MessageConversation $conversacion): array { return $this->lista; }
            public function titularDeAsunto(string $contextType, string $contextId): ?ConversacionEnlaceInterface { return null; }
            /** @return list<ConversacionEnlaceInterface> */
            public function acompanantesDeAsunto(string $contextType, string $contextId): array { return []; }
            public function enlaceDeAsunto(MessageConversation $c, string $t, string $i): ?ConversacionEnlaceInterface { return null; }
        }]);
    }
}
