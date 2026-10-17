<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Queue;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsBookingsPushQueueFactory;
use App\Pms\Service\Exchange\Tasks\BookingsPush\BookingsPushMappingStrategy;
use App\Pms\Service\Queue\Beds24BookingsPushQueueCreator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un link que se va con su reserva viva en Beds24 se RETIRA: se cancela, no se borra.
 *
 * Beds24 rechaza el `DELETE` de una reserva activa —«cannot delete active bookings»—, y así se
 * iban el espejo que sobra al mover de casita y el bloqueo borrado desde el panel. La reserva se
 * quedaba bloqueando la casita vieja. Ver docs/PmsBeds24ReservasSync.md §12.12.7.
 */
final class RetiradaEnBeds24Test extends TestCase
{
    #[Test]
    public function encola_una_cancelacion_sin_link_con_su_marca(): void
    {
        $persistidas = [];
        $creador = $this->creador(existente: null, persistidas: $persistidas);

        self::assertTrue($creador->enqueueRetirada($this->link('2002'), $this->post()));

        self::assertCount(1, $persistidas);
        $fila = $persistidas[0];
        self::assertNull($fila->getLink(), 'sin link: la fila sobrevive al borrado del link');
        self::assertSame('2002', $fila->getBeds24BookIdOriginal());
        self::assertStringStartsWith('retirada:2002:', (string) $fila->getDedupeKey());

        // Y lo que sale hacia Beds24 es la cancelación marcada, que el pull no vuelve a estrenar.
        $fila->initializeId();
        $mapeo = (new BookingsPushMappingStrategy())->map(new HomogeneousBatch(new Beds24Config(), $this->post(), [$fila]));
        self::assertSame([['id' => 2002, 'status' => 'cancelled', 'custom2' => 'RETIRADA']], $mapeo->payload);
    }

    #[Test]
    public function sin_id_en_beds24_no_hay_nada_que_retirar(): void
    {
        $persistidas = [];
        self::assertFalse($this->creador(existente: null, persistidas: $persistidas)->enqueueRetirada($this->link(null), $this->post()));

        self::assertSame([], $persistidas);
    }

    /** Si ya se retiró una vez y vuelve a irse, se repite; no se duplica la fila. */
    #[Test]
    public function una_retirada_ya_hecha_se_reactiva(): void
    {
        $vieja = (new PmsBookingsPushQueue())->setStatus(PmsBookingsPushQueue::STATUS_SUCCESS);
        $persistidas = [];

        $this->creador(existente: $vieja, persistidas: $persistidas)->enqueueRetirada($this->link('2002'), $this->post());

        self::assertSame([], $persistidas);
        self::assertSame(PmsBookingsPushQueue::STATUS_PENDING, $vieja->getStatus());
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    /** @param list<PmsBookingsPushQueue> $persistidas */
    private function creador(?PmsBookingsPushQueue $existente, array &$persistidas): Beds24BookingsPushQueueCreator
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existente);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidas): void {
            if ($o instanceof PmsBookingsPushQueue) {
                $persistidas[] = $o;
            }
        });

        return new Beds24BookingsPushQueueCreator($em, new SyncContext(), new PmsBookingsPushQueueFactory(), new BookingsPushMappingStrategy());
    }

    private function link(?string $bookId): PmsEventoBeds24Link
    {
        $casita = (new PmsUnidad())->setEstablecimiento((new PmsEstablecimiento())->setBeds24Config(new Beds24Config()));
        $mapa = (new PmsUnidadBeds24Map())->setPmsUnidad($casita)->setBeds24RoomId(633675);

        $link = (new PmsEventoBeds24Link())->setUnidadBeds24Map($mapa)->setBeds24BookId($bookId);
        $link->initializeId();

        return $link;
    }

    private function post(): ExchangeEndpoint
    {
        return (new ExchangeEndpoint())
            ->setProvider(ConnectivityProvider::BEDS24)
            ->setAccion(PmsBookingsPushQueue::ACCION_POST_BOOKINGS)
            ->setEndpoint('/bookings')
            ->setMetodo('POST');
    }
}
