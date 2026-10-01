<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Reserva;

use App\Pms\Entity\PmsEstablecimientoVirtual;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Service\Reserva\NochesExtraDeEstancia;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los links extra de una estancia: uno por noche activa y por establecimiento virtual, y ninguno
 * se borra. Ver docs/PlanHorarioExtraSinEventos.md, fase 2.
 */
final class NochesExtraDeEstanciaTest extends TestCase
{
    #[Test]
    public function marcar_la_entrada_crea_una_black_por_listing(): void
    {
        [$casita, $inti, $saphy] = $this->casita();
        $estancia = $this->estancia($casita, entrada: true);

        (new NochesExtraDeEstancia())->sincronizar($estancia);

        $extras = $this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);
        self::assertCount(2, $extras);
        self::assertEqualsCanonicalizing([$inti, $saphy], array_map(static fn ($l) => $l->getUnidadBeds24Map(), $extras));
        self::assertSame([], $this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_SALIDA));
        foreach ($extras as $link) {
            self::assertSame('2027-02-01', $link->nocheQueBloquea()?->desde->format('Y-m-d'));
        }
    }

    #[Test]
    public function es_idempotente(): void
    {
        [$casita] = $this->casita();
        $estancia = $this->estancia($casita, entrada: true, salida: true);
        $servicio = new NochesExtraDeEstancia();

        $servicio->sincronizar($estancia);
        $servicio->sincronizar($estancia);

        self::assertCount(4, $estancia->getBeds24Links());
    }

    /**
     * Mover de casita reutiliza cada link en el mapa de su mismo virtual y conserva su `bookId`:
     * en Beds24 es mover la `black` de habitación, no crear otra.
     */
    #[Test]
    public function mover_de_casita_lleva_las_black_con_su_id(): void
    {
        [$vieja] = $this->casita();
        [$nueva, $nuevaInti, $nuevaSaphy] = $this->casita();
        $estancia = $this->estancia($vieja, entrada: true);
        $servicio = new NochesExtraDeEstancia();
        $servicio->sincronizar($estancia);
        foreach ($this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA) as $i => $link) {
            $link->setBeds24BookId((string) (500 + $i));
        }

        $estancia->setPmsUnidad($nueva);
        $servicio->sincronizar($estancia);

        $extras = $this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);
        self::assertCount(2, $extras);
        self::assertEqualsCanonicalizing([$nuevaInti, $nuevaSaphy], array_map(static fn ($l) => $l->getUnidadBeds24Map(), $extras));
        self::assertEqualsCanonicalizing(['500', '501'], array_map(static fn ($l) => $l->getBeds24BookId(), $extras));
    }

    /** Desmarcar no borra nada: el link se queda y su `black` sale como cancelada. */
    #[Test]
    public function desmarcar_deja_los_links_y_dejan_de_bloquear(): void
    {
        [$casita] = $this->casita();
        $estancia = $this->estancia($casita, entrada: true);
        $servicio = new NochesExtraDeEstancia();
        $servicio->sincronizar($estancia);

        $estancia->setEntradaTemprana(false);
        $servicio->sincronizar($estancia);

        $extras = $this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);
        self::assertCount(2, $extras);
        foreach ($extras as $link) {
            self::assertNull($link->nocheQueBloquea());
        }
    }

    /**
     * La casita nueva no tiene uno de los dos virtuales: el link de ése se queda en la vieja con
     * su `bookId` y deja de bloquear, en vez de llevarse su `black` a otro listing.
     */
    #[Test]
    public function un_link_sin_pareja_en_la_casita_nueva_se_queda_y_deja_de_bloquear(): void
    {
        [$vieja] = $this->casita();
        $nueva = new PmsUnidad();
        $soloInti = (new PmsUnidadBeds24Map())->setVirtualEstablecimiento($this->virtual('INTI', true))->setActivo(true);
        $nueva->addBeds24Map($soloInti);

        $estancia = $this->estancia($vieja, entrada: true);
        $servicio = new NochesExtraDeEstancia();
        $servicio->sincronizar($estancia);
        foreach ($this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA) as $i => $link) {
            $link->setBeds24BookId((string) (700 + $i));
        }

        $estancia->setPmsUnidad($nueva);
        $servicio->sincronizar($estancia);

        $bloquean = array_filter($this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA), static fn ($l) => $l->nocheQueBloquea() !== null);
        self::assertCount(2, $this->extras($estancia, PmsEventoBeds24Link::ROL_EXTRA_ENTRADA));
        self::assertCount(1, $bloquean);
        self::assertSame($soloInti, array_values($bloquean)[0]->getUnidadBeds24Map());
    }

    #[Test]
    public function los_links_de_la_estancia_no_se_tocan(): void
    {
        [$casita, $inti] = $this->casita();
        $estancia = $this->estancia($casita, entrada: true);
        $principal = (new PmsEventoBeds24Link())->setUnidadBeds24Map($inti)->setBeds24BookId('100')->hacerPrincipal();
        $estancia->addBeds24Link($principal);

        (new NochesExtraDeEstancia())->sincronizar($estancia);

        self::assertSame($inti, $principal->getUnidadBeds24Map());
        self::assertSame(PmsEventoBeds24Link::ROL_ESTANCIA, $principal->getRol());
        self::assertSame('100', $principal->getBeds24BookId());
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    /** @return array{PmsUnidad, PmsUnidadBeds24Map, PmsUnidadBeds24Map} */
    private function casita(): array
    {
        $unidad = new PmsUnidad();
        $inti = (new PmsUnidadBeds24Map())->setVirtualEstablecimiento($this->virtual('INTI', true))->setActivo(true);
        $saphy = (new PmsUnidadBeds24Map())->setVirtualEstablecimiento($this->virtual('SAPHY', false))->setActivo(true);
        $unidad->addBeds24Map($inti)->addBeds24Map($saphy);

        return [$unidad, $inti, $saphy];
    }

    private function virtual(string $codigo, bool $principal): PmsEstablecimientoVirtual
    {
        return (new PmsEstablecimientoVirtual())->setCodigo($codigo)->setEsPrincipal($principal);
    }

    private function estancia(PmsUnidad $casita, bool $entrada = false, bool $salida = false): PmsEventoCalendario
    {
        return (new PmsEventoCalendario())
            ->setPmsUnidad($casita)
            ->setInicio(new DateTimeImmutable('2027-02-02 14:00'))
            ->setFin(new DateTimeImmutable('2027-02-05 10:00'))
            ->setEntradaTemprana($entrada)
            ->setSalidaTardia($salida)
            ->setEstado(new PmsEventoEstado(PmsEventoEstado::CODIGO_CONFIRMADA));
    }

    /** @return list<PmsEventoBeds24Link> */
    private function extras(PmsEventoCalendario $estancia, string $rol): array
    {
        return array_values(array_filter(
            $estancia->getBeds24Links()->toArray(),
            static fn (PmsEventoBeds24Link $l): bool => $l->getRol() === $rol
        ));
    }
}
