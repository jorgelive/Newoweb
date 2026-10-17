<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Queue;

use App\Exchange\Entity\Beds24Config;
use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Service\Context\SyncContext;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsBookingsPushQueueFactory;
use App\Pms\Service\Exchange\Tasks\BookingsPush\BookingsPushMappingStrategy;
use App\Pms\Service\Queue\Beds24BookingsPushQueueCreator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cola de push decide por la HUELLA de lo que viajaría, y no toca una fila que se está enviando.
 *
 * Tres fallos que esto cierra (docs/PmsBeds24ReservasSync.md §7.1.a):
 *  - Lo ya enviado se reenviaba en cada pull: una fila en `success` se reciclaba aunque nada
 *    hubiera cambiado.
 *  - La huella era una lista propia (fechas, estado, habitación) que no veía el huésped ni el
 *    importe: con el primer arreglo, un cambio de nombre no habría salido nunca.
 *  - Reciclar una fila en `processing` le quitaba el candado: otro worker podía reclamarla a la
 *    vez (dos POST, dos reservas) y el primero la cerraba en `success` perdiendo el cambio.
 */
final class ColaDePushHuellaTest extends TestCase
{
    private PmsBookingsPushQueue $fila;
    private PmsEventoBeds24Link $link;
    private PmsReserva $reserva;
    private ExchangeEndpoint $endpoint;

    protected function setUp(): void
    {
        $establecimiento = (new PmsEstablecimiento())->setBeds24Config(new Beds24Config());
        $casita = (new PmsUnidad())->setEstablecimiento($establecimiento);
        $mapa = (new PmsUnidadBeds24Map())->setBeds24RoomId(633675)->setActivo(true)->setPmsUnidad($casita);
        $casita->addBeds24Map($mapa);

        $this->reserva = (new PmsReserva())->setNombreCliente('Anna')->setApellidoCliente('Müller');

        $estancia = (new PmsEventoCalendario())
            ->setPmsUnidad($casita)
            ->setReserva($this->reserva)
            ->setInicio(new DateTimeImmutable('2027-02-02 14:00'))
            ->setFin(new DateTimeImmutable('2027-02-05 10:00'))
            ->setIsOta(false)
            ->setEstado(new PmsEventoEstado(PmsEventoEstado::CODIGO_CONFIRMADA));

        $this->link = (new PmsEventoBeds24Link())->setUnidadBeds24Map($mapa)->setBeds24BookId('93900001');
        $this->link->initializeId();
        $estancia->addBeds24Link($this->link);

        $this->endpoint = (new ExchangeEndpoint())
            ->setProvider(ConnectivityProvider::BEDS24)
            ->setAccion(PmsBookingsPushQueue::ACCION_POST_BOOKINGS)
            ->setMetodo('POST');

        $this->fila = new PmsBookingsPushQueue();
        $this->fila->setPayloadHash('huella-de-antes');
        $this->fila->setStatus(PmsBookingsPushQueue::STATUS_SUCCESS);
    }

    #[Test]
    public function lo_ya_enviado_no_se_reenvia(): void
    {
        $this->encolar();                                       // la huella vieja no cuadra: sale
        self::assertSame(PmsBookingsPushQueue::STATUS_PENDING, $this->fila->getStatus());

        $this->fila->markSuccess(new DateTimeImmutable());
        $this->encolar();                                       // nada nuevo que contar

        self::assertSame(PmsBookingsPushQueue::STATUS_SUCCESS, $this->fila->getStatus());
    }

    /** Un dato del huésped cuenta: antes la huella sólo veía fechas, estado y habitación. */
    #[Test]
    public function un_cambio_de_nombre_si_sale(): void
    {
        $this->encolar();
        $this->fila->markSuccess(new DateTimeImmutable());

        $this->reserva->setApellidoCliente('Müller-Lüdenscheidt');
        $this->encolar();

        self::assertSame(PmsBookingsPushQueue::STATUS_PENDING, $this->fila->getStatus());
    }

    #[Test]
    public function una_fila_en_envio_conserva_su_candado_y_apunta_la_huella_nueva(): void
    {
        $this->fila->setStatus(PmsBookingsPushQueue::STATUS_PROCESSING);
        $this->fila->setLockedBy('worker-1');
        $this->fila->setLockedAt(new DateTimeImmutable());

        $this->encolar();

        self::assertSame(PmsBookingsPushQueue::STATUS_PROCESSING, $this->fila->getStatus());
        self::assertSame('worker-1', $this->fila->getLockedBy(), 'sin candado, otro worker la reclamaría a la vez');
        self::assertNotSame('huella-de-antes', $this->fila->getPayloadHash(), 'el handler la compara al acabar');
    }

    private function encolar(): void
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($this->fila);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        (new Beds24BookingsPushQueueCreator($em, new SyncContext(), new PmsBookingsPushQueueFactory(), new BookingsPushMappingStrategy()))
            ->enqueueForLink($this->link, $this->endpoint);
    }
}
