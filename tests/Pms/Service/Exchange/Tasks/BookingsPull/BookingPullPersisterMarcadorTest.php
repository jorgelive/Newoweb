<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Exchange\Tasks\BookingsPull;

use App\Exchange\Entity\Beds24Config;
use App\Pms\Dto\Beds24BookingDto;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoBeds24Link;
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
 * El `custom1` no manda sobre el `bookId`: un link ocupado no se adopta.
 *
 * Por qué existe este test: `custom1` («PMS:<uuid del link>») es nuestra ancla de identidad, pero
 * en Beds24 NO es única. El pull lo resolvía PRIMERO, así que una segunda reserva con el mismo
 * marcador se adoptaba encima de la primera y el link pasaba a seguir a la equivocada.
 *
 * Con Melanie (29ZY2P, 13–15/11/2026) eso costó una estancia: `93628251` seguía confirmada en
 * Beds24, el operador canceló la duplicada `93628253` creyendo que limpiaba, y la cancelación
 * entró al PMS como si fuera la reserva buena. El fallo no daba error: la casita quedó libre en
 * el calendario y vendida en el canal.
 */
final class BookingPullPersisterMarcadorTest extends TestCase
{
    private const LINK_UUID = '01a0d31c-0c12-7d79-81b1-a0bfe59176d8';

    /** Llega la duplicada: el link ya sigue a otra reserva, así que no se toca nada. */
    #[Test]
    public function un_link_ocupado_no_adopta_la_segunda_reserva_con_su_marcador(): void
    {
        $ocupado = $this->link('93628251');

        $resultado = $this->persister($ocupado, porBookId: null)
            ->upsert($this->config(), $this->booking(93628253));

        self::assertSame('skipped', $resultado['status']);
        self::assertSame('ignored', $resultado['action']);
        self::assertStringContainsString('93628251', $resultado['message'], 'debe decir a quién sigue el link');
        self::assertSame('93628251', $ocupado->getBeds24BookId(), 'el link no se mueve');
    }

    /**
     * Y la buena sigue entrando por la puerta del `bookId`, que es la que manda. Si esto se
     * rompiera, la guarda de arriba dejaría fuera a las dos y el pull no escribiría nunca.
     *
     * Aquí se comprueba SÓLO eso: que no sale por la puerta del marcador duplicado. Llegar al
     * final pide media base de datos, y este andamiaje se queda mucho antes — por eso lo que se
     * afirma es sobre el motivo, no sobre el estado.
     */
    #[Test]
    public function la_reserva_que_el_link_ya_sigue_no_la_frena_la_guarda(): void
    {
        $ocupado = $this->link('93628251');

        $resultado = $this->persister($ocupado, porBookId: $ocupado)
            ->upsert($this->config(), $this->booking(93628251));

        self::assertStringNotContainsString(
            'Marcador PMS duplicado',
            $resultado['message'],
            'la reserva que el link ya sigue no es un marcador duplicado',
        );
    }

    /**
     * Una `black` de horario extra (`custom2 = EXTRA`) que ningún link reclama no estrena evento:
     * es nuestra, como un espejo. Sin la guarda se adoptaría como reserva nueva — una estancia
     * fantasma de una noche encima de la víspera de otra.
     */
    #[Test]
    public function una_noche_extra_huerfana_no_estrena_nada(): void
    {
        $resultado = $this->persister(null, porBookId: null)
            ->upsert($this->config(), $this->booking(93900001, 'EXTRA', 'confirmed'));

        self::assertSame('skipped', $resultado['status']);
        self::assertStringContainsString('Noche extra huérfana', $resultado['message']);
    }

    #[Test]
    public function un_espejo_huerfano_tampoco(): void
    {
        $resultado = $this->persister(null, porBookId: null)
            ->upsert($this->config(), $this->booking(93900002, 'MIRROR', 'confirmed'));

        self::assertSame('skipped', $resultado['status']);
        self::assertStringContainsString('Espejo huérfano', $resultado['message']);
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    private function link(string $bookId): PmsEventoBeds24Link
    {
        $link = new PmsEventoBeds24Link();
        $link->initializeId();

        $reflexion = new \ReflectionProperty($link, 'id');
        $reflexion->setValue($link, \Symfony\Component\Uid\Uuid::fromString(self::LINK_UUID));

        return $link->setBeds24BookId($bookId);
    }

    private ?PmsEstablecimiento $establecimiento = null;

    private function establecimiento(): PmsEstablecimiento
    {
        if ($this->establecimiento === null) {
            $this->establecimiento = new PmsEstablecimiento();
            $this->establecimiento->initializeId();
        }

        return $this->establecimiento;
    }

    private function config(): Beds24Config
    {
        return (new Beds24Config())->addEstablecimiento($this->establecimiento());
    }

    private function booking(int $id, string $custom2 = 'PRINCIPAL', string $status = 'cancelled'): Beds24BookingDto
    {
        return Beds24BookingDto::fromArray([
            'id' => $id,
            'propertyId' => 303948,
            'roomId' => 633675,
            'status' => $status,
            'arrival' => '2026-11-13',
            'departure' => '2026-11-15',
            'custom1' => 'PMS:' . self::LINK_UUID,
            'custom2' => $custom2,
        ]);
    }

    private function persister(?PmsEventoBeds24Link $porMarcador, ?PmsEventoBeds24Link $porBookId): BookingPullPersister
    {
        $unidad = (new PmsUnidad())->setEstablecimiento($this->establecimiento());

        $map = (new PmsUnidadBeds24Map())
            ->setPmsUnidad($unidad)
            ->setBeds24RoomId(633675)
            ->setBeds24PropertyId(303948);

        $repoMap = $this->createStub(EntityRepository::class);
        $repoMap->method('findOneBy')->willReturn($map);

        $repoLink = $this->createStub(EntityRepository::class);
        $repoLink->method('findOneBy')->willReturn($porBookId);
        $repoLink->method('find')->willReturn($porMarcador);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $clase): EntityRepository => $clase === PmsEventoBeds24Link::class ? $repoLink : $repoMap
        );

        return new BookingPullPersister(
            $em,
            $this->createStub(PmsEventoCalendarioFactory::class),
            new PhoneSanitizer(),
            new NombreSanitizer(),
            new NullLogger(),
        );
    }
}
