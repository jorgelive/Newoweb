<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Service\Exchange\Tasks\BookingsPull\BookingPullPersister;
use App\Service\Nombre\NombreSanitizer;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lo que el pull de un ESPEJO puede y no puede tocar.
 *
 * Un espejo cuelga del mismo evento que la reserva real, con rol `estancia` como ella, y en
 * Beds24 lleva lo que le mandamos la última vez. Su pull confirma que sigue vivo y nada más:
 *  - no mueve la estancia de casita (el operador la movió y el push del espejo aún no salió);
 *  - no le pisa la nota a la reserva;
 *  - pero si se adoptó por su `custom1`, sí se le anota el id, o el siguiente push lo duplica.
 *
 * Se llama a los métodos privados por reflexión: el camino completo de `upsert()` pide los
 * maestros de estado, canal, país e idioma, y el de un espejo no pasa por ninguno.
 * Ver docs/PmsBeds24ReservasSync.md §6.4.
 */
final class PullDeUnEspejoTest extends TestCase
{
    #[Test]
    public function el_pull_de_un_espejo_no_devuelve_la_estancia_a_la_casita_vieja(): void
    {
        $nueva = $this->casita();
        $vieja = $this->casita();
        $mapaViejo = (new PmsUnidadBeds24Map())->setPmsUnidad($vieja)->setBeds24RoomId(111);

        $evento = (new PmsEventoCalendario())->setPmsUnidad($nueva);
        $espejo = (new PmsEventoBeds24Link())->setUnidadBeds24Map($mapaViejo)->setBeds24BookId('2002');
        $evento->addBeds24Link($espejo);

        $factory = $this->createMock(PmsEventoCalendarioFactory::class);
        $factory->expects(self::never())->method('rebuildLinks');

        $this->upsertEvento($factory, $mapaViejo, $espejo, '2002', esPrincipal: false);

        self::assertSame($nueva, $evento->getPmsUnidad());
    }

    /** El principal sí: si la OTA cambió la habitación, la estancia va detrás. */
    #[Test]
    public function el_del_principal_si_la_mueve(): void
    {
        $nueva = $this->casita();
        $otra = $this->casita();
        $mapaOtra = (new PmsUnidadBeds24Map())->setPmsUnidad($otra)->setBeds24RoomId(222);

        $evento = (new PmsEventoCalendario())->setPmsUnidad($nueva);
        $principal = (new PmsEventoBeds24Link())->setUnidadBeds24Map($mapaOtra)->setBeds24BookId('1001')->hacerPrincipal();
        $evento->addBeds24Link($principal);

        $factory = $this->createMock(PmsEventoCalendarioFactory::class);
        $factory->expects(self::once())->method('rebuildLinks');

        try {
            $this->upsertEvento($factory, $mapaOtra, $principal, '1001', esPrincipal: true);
        } catch (\Throwable) {
            // Lo que viene después (estado, canal…) pide maestros que aquí no hay; lo que se
            // comprueba es que la reconstrucción se pidió.
        }

        self::assertSame($otra, $evento->getPmsUnidad());
    }

    #[Test]
    public function un_espejo_adoptado_por_su_marcador_se_queda_con_su_id(): void
    {
        $casita = $this->casita();
        $mapa = (new PmsUnidadBeds24Map())->setPmsUnidad($casita)->setBeds24RoomId(111);

        $evento = (new PmsEventoCalendario())->setPmsUnidad($casita);
        $espejo = (new PmsEventoBeds24Link())->setUnidadBeds24Map($mapa);   // libre: sin id todavía
        $evento->addBeds24Link($espejo);

        $this->upsertEvento($this->createStub(PmsEventoCalendarioFactory::class), $mapa, $espejo, '2002', esPrincipal: false);

        self::assertSame('2002', $espejo->getBeds24BookId(), 'sin id, el siguiente push crearía otro espejo');
    }

    #[Test]
    public function el_pull_de_un_espejo_no_toca_la_nota(): void
    {
        $reserva = $this->reservaConNota('Llega con perro');

        $this->upsertReservaFull($reserva, notes: null, esPrincipal: false);
        self::assertSame('Llega con perro', $reserva->getNota());

        $this->upsertReservaFull($reserva, notes: 'nota vieja del espejo', esPrincipal: false);
        self::assertSame('Llega con perro', $reserva->getNota());
    }

    #[Test]
    public function el_principal_la_escribe_pero_un_vacio_no_la_borra(): void
    {
        $reserva = $this->reservaConNota('Llega con perro');

        $this->upsertReservaFull($reserva, notes: '', esPrincipal: true);
        self::assertSame('Llega con perro', $reserva->getNota());

        $this->upsertReservaFull($reserva, notes: 'Llega con dos perros', esPrincipal: true);
        self::assertSame('Llega con dos perros', $reserva->getNota());
    }

    /**
     * Dos estancias sueltas de la misma reserva: cada una llega con su link principal, y la
     * reserva se quedaba con los ids de la última que pasaba — alternando de pasada en pasada.
     */
    #[Test]
    public function dos_estancias_sin_grupo_no_se_pisan_los_ids(): void
    {
        $reserva = $this->reservaConNota('');

        $this->upsertReservaFull($reserva, notes: null, esPrincipal: true, id: 1001);
        $this->upsertReservaFull($reserva, notes: null, esPrincipal: true, id: 1002);

        self::assertSame('1001', $reserva->getBeds24MasterId());
        self::assertSame('1001', $reserva->getBeds24BookIdPrincipal());
    }

    /** Cuando Beds24 declara el grupo, la madre manda aunque la reserva dijera otra cosa. */
    #[Test]
    public function la_madre_de_un_grupo_declarado_si_los_escribe(): void
    {
        $reserva = $this->reservaConNota('');
        $reserva->setBeds24MasterId('1002')->setBeds24BookIdPrincipal('1002');

        $this->upsertReservaFull($reserva, notes: null, esPrincipal: true, id: 1001, grupo: ['master' => 1001, 'ids' => [1001, 1002]]);

        self::assertSame('1001', $reserva->getBeds24MasterId());
        self::assertSame('1001', $reserva->getBeds24BookIdPrincipal());
    }

    /**
     * La hija de un grupo llegó antes que la madre y su estancia se tituló con el stub. Si la
     * madre entra en el mismo lote, la reserva aún es una inserción y el listener de cambio de
     * nombre —que sólo mira actualizaciones— no la retitulaba.
     */
    #[Test]
    public function la_madre_le_quita_a_la_hija_el_titulo_de_stub(): void
    {
        $reserva = $this->reservaConNota('')->setNombreCliente('Pendiente Sync')->setApellidoCliente('(Grupo)');
        $hija = (new PmsEventoCalendario())->setTituloCache('Pendiente Sync (Grupo)');
        $reserva->addEventosCalendario($hija);
        $retitulada = (new PmsEventoCalendario())->setTituloCache('Ana a mano');
        $reserva->addEventosCalendario($retitulada);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $clase) => match ($clase) {
            MaestroPais::class => new MaestroPais('PE', 'Perú'),
            default => new MaestroIdioma('es', 'Español'),
        });
        $canales = $this->createStub(EntityRepository::class);
        $canales->method('find')->willReturn(new PmsChannel(PmsChannel::CODIGO_DIRECTO));
        $em->method('getRepository')->willReturn($canales);

        (new \ReflectionMethod(BookingPullPersister::class, 'upsertReservaFull'))->invoke(
            new BookingPullPersister($em, $this->createStub(PmsEventoCalendarioFactory::class), new PhoneSanitizer(), new NombreSanitizer(), new NullLogger()),
            Beds24BookingDto::fromArray(['id' => 1001, 'roomId' => 111, 'firstName' => 'Ana', 'lastName' => 'Quispe', 'bookingGroup' => ['master' => 1001, 'ids' => [1001, 1002]]]),
            true,
            new PmsEstablecimiento(),
            $reserva,
        );

        self::assertSame('Ana Quispe', $hija->getTituloCache());
        self::assertSame('Ana a mano', $retitulada->getTituloCache(), 'un título que no es el del stub no se toca');
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    private function casita(): PmsUnidad
    {
        $unidad = (new PmsUnidad())->setEstablecimiento(new PmsEstablecimiento());
        $unidad->initializeId();

        return $unidad;
    }

    private function reservaConNota(string $nota): PmsReserva
    {
        $reserva = (new PmsReserva())->setNota($nota);
        $reserva->setIdioma(new MaestroIdioma('es', 'Español'));

        return $reserva;
    }

    private function persister(PmsEventoCalendarioFactory $factory): BookingPullPersister
    {
        return new BookingPullPersister(
            $this->createStub(EntityManagerInterface::class),
            $factory,
            new PhoneSanitizer(),
            new NombreSanitizer(),
            new NullLogger(),
        );
    }

    private function upsertEvento(
        PmsEventoCalendarioFactory $factory,
        PmsUnidadBeds24Map $mapa,
        PmsEventoBeds24Link $link,
        string $bookId,
        bool $esPrincipal,
    ): void {
        $booking = Beds24BookingDto::fromArray([
            'id' => (int) $bookId,
            'roomId' => $mapa->getBeds24RoomId(),
            'status' => 'confirmed',
            'arrival' => '2026-11-13',
            'departure' => '2026-11-15',
        ]);

        (new \ReflectionMethod(BookingPullPersister::class, 'upsertEvento'))->invoke(
            $this->persister($factory),
            $booking,
            $mapa,
            null,
            $link,
            $bookId,
            $esPrincipal,
        );
    }

    /** @param array<string, mixed>|null $grupo */
    private function upsertReservaFull(PmsReserva $reserva, ?string $notes, bool $esPrincipal, int $id = 2002, ?array $grupo = null): void
    {
        $booking = Beds24BookingDto::fromArray(['id' => $id, 'roomId' => 111, 'notes' => $notes, 'bookingGroup' => $grupo]);

        (new \ReflectionMethod(BookingPullPersister::class, 'upsertReservaFull'))->invoke(
            $this->persister($this->createStub(PmsEventoCalendarioFactory::class)),
            $booking,
            $esPrincipal,
            new PmsEstablecimiento(),
            $reserva,
        );
    }
}
