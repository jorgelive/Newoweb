<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPush;

use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Exchange\Tasks\BookingsPush\BookingsPushHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un envío que sale bien pero con datos que ya cambiaron vuelve a la cola.
 *
 * Mientras el worker está en la petición, la cola sólo le apunta la huella nueva a la fila (no le
 * quita el candado). Al acabar, el handler compara: si la huella de la base no es la que reclamó,
 * lo enviado es la foto de antes. Ver docs/PmsBeds24ReservasSync.md §7.1.a.
 */
final class BookingsPushHandlerTest extends TestCase
{
    #[Test]
    public function si_nadie_la_toco_queda_enviada_y_el_link_con_su_id(): void
    {
        [$fila, $link] = $this->filaEnviando();

        $this->handler(huellaEnBase: 'h1')->handleSuccess(['success' => true, 'new' => ['id' => 93900001]], $fila);

        self::assertSame(PmsBookingsPushQueue::STATUS_SUCCESS, $fila->getStatus());
        self::assertSame('93900001', $link->getBeds24BookId());
    }

    #[Test]
    public function si_cambio_mientras_se_enviaba_vuelve_a_pendiente_sin_perder_el_id(): void
    {
        [$fila, $link] = $this->filaEnviando();

        $this->handler(huellaEnBase: 'h2')->handleSuccess(['success' => true, 'new' => ['id' => 93900001]], $fila);

        self::assertSame(PmsBookingsPushQueue::STATUS_PENDING, $fila->getStatus());
        self::assertNull($fila->getLockedBy());
        self::assertSame('93900001', $link->getBeds24BookId(), 'el reenvío ya es una modificación, no otra alta');
    }

    /**
     * La primera estancia propia que nace en Beds24 es la madre del grupo: sin esto, las demás
     * estancias de la reserva salían sin `masterId` y quedaban sueltas allí.
     */
    #[Test]
    public function la_primera_estancia_propia_es_la_madre_del_grupo(): void
    {
        [$fila, $link] = $this->filaEnviando();
        $reserva = new PmsReserva();
        $evento = (new PmsEventoCalendario())->setIsOta(false)->setReserva($reserva);
        $evento->addBeds24Link($link->hacerPrincipal());

        $this->handler(huellaEnBase: 'h1')->handleSuccess(['success' => true, 'new' => ['id' => 93900001]], $fila);

        self::assertSame('93900001', $reserva->getBeds24MasterId());
        self::assertSame('93900001', $reserva->getBeds24BookIdPrincipal());
    }

    /** En una OTA el grupo lo dice el canal; y un espejo no es la estancia. */
    #[Test]
    public function ni_una_ota_ni_un_espejo_fundan_grupo(): void
    {
        foreach ([[true, true], [false, false]] as [$ota, $principal]) {
            [$fila, $link] = $this->filaEnviando();
            $reserva = new PmsReserva();
            $evento = (new PmsEventoCalendario())->setIsOta($ota)->setReserva($reserva);
            $evento->addBeds24Link($principal ? $link->hacerPrincipal() : $link);

            $this->handler(huellaEnBase: 'h1')->handleSuccess(['success' => true, 'new' => ['id' => 93900001]], $fila);

            self::assertNull($reserva->getBeds24MasterId());
        }
    }

    /** @return array{PmsBookingsPushQueue, PmsEventoBeds24Link} */
    private function filaEnviando(): array
    {
        $link = new PmsEventoBeds24Link();
        $link->initializeId();

        $fila = new PmsBookingsPushQueue();
        $fila->initializeId();
        $fila->setLink($link);
        $fila->setPayloadHash('h1');
        $fila->markProcessing('worker-1', new \DateTimeImmutable());

        return [$fila, $link];
    }

    private function handler(string $huellaEnBase): BookingsPushHandler
    {
        $conexion = $this->createStub(Connection::class);
        $conexion->method('fetchOne')->willReturn($huellaEnBase);

        return new BookingsPushHandler($conexion);
    }
}
