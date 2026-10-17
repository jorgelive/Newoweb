<?php

declare(strict_types=1);

namespace App\Tests\Pms\Factory;

use App\Pms\Entity\PmsEstablecimientoVirtual;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mover una estancia entre casitas con canales DISTINTOS.
 *
 * Hoy las siete casitas tienen los mismos dos establecimientos virtuales (INTI y SAPHY), así que
 * nada de esto ha pasado. Deja de ser imposible en cuanto una casita tenga un tercer canal o
 * pierda uno —la migración de Beds24 a otra plataforma lo traerá—. Ver
 * docs/PmsBeds24ReservasSync.md §6.3.b.
 *
 *  - Una reserva de Beds24 es de UNA propiedad: el principal no puede viajar a otro virtual.
 *  - Lo que se suelta se retira en Beds24 (lo hace el listener de la cola al ver el link irse).
 *  - Una casita sin mapeos no deja los links en la vieja.
 */
final class HidratadorEntreVirtualesTest extends TestCase
{
    /** SAPHY (principal) + INTI (espejo) → una casita sólo con INTI: el espejo de INTI releva. */
    #[Test]
    public function el_principal_no_cruza_de_virtual_y_lo_releva_el_espejo_de_alli(): void
    {
        [$vieja, $viejaSaphy, $viejaInti] = $this->casita(['SAPHY', 'INTI']);
        [$nueva, $nuevaInti] = $this->casita(['INTI']);

        $reserva = (new PmsReserva())->setBeds24MasterId('1001')->setBeds24BookIdPrincipal('1001');
        $evento = (new PmsEventoCalendario())->setPmsUnidad($vieja)->setReserva($reserva);
        $principal = $this->colgar($evento, $viejaSaphy, '1001')->hacerPrincipal();
        $espejo = $this->colgar($evento, $viejaInti, '2002');

        $evento->setPmsUnidad($nueva);
        $this->factory()->hydrateLinksForUi($evento);

        self::assertFalse($evento->getBeds24Links()->contains($principal), 'la de SAPHY se suelta y se retira');
        self::assertTrue($espejo->isEsPrincipal(), 'el espejo de INTI pasa a ser la estancia');
        self::assertSame('2002', $espejo->getBeds24BookId(), 'conserva SU reserva, que es de INTI');
        self::assertSame($nuevaInti, $espejo->getUnidadBeds24Map());
        self::assertCount(1, $evento->getLinksDeEstancia());
        self::assertNull($reserva->getBeds24MasterId(), 'no se agrupa con una reserva que se cancela');
        self::assertNull($reserva->getBeds24BookIdPrincipal());
    }

    /** Si en el virtual nuevo no había nadie, nace un principal sin id: el push lo creará allí. */
    #[Test]
    public function sin_espejo_en_el_virtual_nuevo_nace_uno_sin_id(): void
    {
        [$vieja, $viejaSaphy, $viejaInti] = $this->casita(['SAPHY', 'INTI']);
        [$nueva, $nuevaOtro] = $this->casita(['OTRO']);

        $evento = (new PmsEventoCalendario())->setPmsUnidad($vieja);
        $principal = $this->colgar($evento, $viejaSaphy, '1001')->hacerPrincipal();
        $espejo = $this->colgar($evento, $viejaInti, '2002');

        $evento->setPmsUnidad($nueva);
        $this->factory()->hydrateLinksForUi($evento);

        $links = $evento->getLinksDeEstancia();
        self::assertCount(1, $links);
        self::assertNotContains($principal, $links);
        self::assertNotContains($espejo, $links);
        self::assertTrue($links[0]->isEsPrincipal());
        self::assertNull($links[0]->getBeds24BookId());
        self::assertSame($nuevaOtro, $links[0]->getUnidadBeds24Map());
    }

    /** Dentro del mismo virtual sí viaja, con su id: es un cambio de habitación. */
    #[Test]
    public function en_el_mismo_virtual_el_principal_viaja_con_su_reserva(): void
    {
        [$vieja, $viejaSaphy] = $this->casita(['SAPHY']);
        [$nueva, $nuevaSaphy] = $this->casita(['SAPHY']);

        $evento = (new PmsEventoCalendario())->setPmsUnidad($vieja);
        $principal = $this->colgar($evento, $viejaSaphy, '1001')->hacerPrincipal();

        $evento->setPmsUnidad($nueva);
        $this->factory()->hydrateLinksForUi($evento);

        self::assertSame($nuevaSaphy, $principal->getUnidadBeds24Map());
        self::assertSame('1001', $principal->getBeds24BookId());
    }

    /** Desde un webhook manda Beds24: el id y la habitación los acaba de decir él. */
    #[Test]
    public function desde_un_webhook_el_principal_va_donde_diga_beds24(): void
    {
        [$vieja, $viejaSaphy] = $this->casita(['SAPHY']);
        [$nueva, $nuevaInti] = $this->casita(['INTI']);
        $nuevaInti->setBeds24RoomId(777);

        $evento = (new PmsEventoCalendario())->setPmsUnidad($vieja);
        $principal = $this->colgar($evento, $viejaSaphy, '1001')->hacerPrincipal();

        $evento->setPmsUnidad($nueva);
        $this->factory()->rebuildLinks($evento, '1001', 777);

        self::assertSame($nuevaInti, $principal->getUnidadBeds24Map());
        self::assertSame('1001', $principal->getBeds24BookId());
    }

    /** A una casita que no está en Beds24: los links de la estancia se sueltan (y se retiran). */
    #[Test]
    public function a_una_casita_sin_mapeos_se_sueltan_los_de_la_estancia(): void
    {
        [$vieja, $viejaSaphy, $viejaInti] = $this->casita(['SAPHY', 'INTI']);
        $sinBeds24 = new PmsUnidad();

        $evento = (new PmsEventoCalendario())->setPmsUnidad($vieja);
        $this->colgar($evento, $viejaSaphy, '1001')->hacerPrincipal();
        $this->colgar($evento, $viejaInti, '2002');
        $extra = $this->colgar($evento, $viejaSaphy, '3003')->setRol(PmsEventoBeds24Link::ROL_EXTRA_SALIDA);

        $evento->setPmsUnidad($sinBeds24);
        $this->factory()->hydrateLinksForUi($evento);

        self::assertSame([], $evento->getLinksDeEstancia());
        self::assertTrue($evento->getBeds24Links()->contains($extra), 'los extra los lleva su servicio');
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    /**
     * Una casita con un mapa por código; el primero es el virtual principal.
     *
     * @param list<string> $codigos
     * @return array{0: PmsUnidad, 1: PmsUnidadBeds24Map, 2?: PmsUnidadBeds24Map}
     */
    private function casita(array $codigos): array
    {
        $unidad = new PmsUnidad();
        $resultado = [$unidad];
        foreach ($codigos as $i => $codigo) {
            $virtual = (new PmsEstablecimientoVirtual())->setCodigo($codigo)->setEsPrincipal($i === 0);
            $mapa = (new PmsUnidadBeds24Map())->setVirtualEstablecimiento($virtual)->setActivo(true);
            $unidad->addBeds24Map($mapa);
            $resultado[] = $mapa;
        }

        /** @var array{0: PmsUnidad, 1: PmsUnidadBeds24Map, 2?: PmsUnidadBeds24Map} $resultado */
        return $resultado;
    }

    private function colgar(PmsEventoCalendario $evento, PmsUnidadBeds24Map $mapa, ?string $bookId): PmsEventoBeds24Link
    {
        $link = (new PmsEventoBeds24Link())->setUnidadBeds24Map($mapa)->setBeds24BookId($bookId);
        $evento->addBeds24Link($link);

        return $link;
    }

    private function factory(): PmsEventoCalendarioFactory
    {
        return new PmsEventoCalendarioFactory($this->createStub(EntityManagerInterface::class));
    }
}
