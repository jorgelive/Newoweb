<?php

declare(strict_types=1);

namespace App\Tests\Pms\Factory;

use App\Pms\Entity\PmsEstablecimientoVirtual;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El hidratador de links (`internalHydrate()`) sólo toca los links de la estancia.
 *
 * Reparte principal y espejos entre los mapas de la casita, y lo que no encuentra pareja lo
 * suelta — y un link soltado se borra con su DELETE a Beds24. Un link extra (la `black` de un
 * horario extra) no es ni principal ni espejo: si entrara al reparto, acabaría en los sobrantes y
 * la noche extra quedaría libre en el channel manager sin que nadie la hubiera desmarcado.
 */
final class HidratadorIgnoraLinksExtraTest extends TestCase
{
    #[Test]
    public function rehidratar_en_la_misma_casita_no_toca_el_extra(): void
    {
        [$unidad, $mapaPrincipal, $mapaEspejo] = $this->casita('INTI', 'SAPHY');
        $evento = (new PmsEventoCalendario())->setPmsUnidad($unidad);

        $principal = $this->colgar($evento, $mapaPrincipal, '100')->hacerPrincipal();
        $espejo = $this->colgar($evento, $mapaEspejo, '101');
        $extra = $this->colgar($evento, $mapaPrincipal, '200')->setRol(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);

        $this->factory()->hydrateLinksForUi($evento);

        self::assertCount(3, $evento->getBeds24Links());
        self::assertTrue($evento->getBeds24Links()->contains($extra), 'el extra no se suelta');
        self::assertSame('200', $extra->getBeds24BookId());
        self::assertFalse($extra->isEsPrincipal());
        self::assertSame($mapaPrincipal, $extra->getUnidadBeds24Map());
        self::assertSame('100', $principal->getBeds24BookId(), 'el principal no le cede su reserva a nadie');
        self::assertSame('101', $espejo->getBeds24BookId());
    }

    /**
     * Al mover de casita, principal y espejo se mueven y el extra se queda donde estaba: llevarlo
     * a la casita nueva es trabajo de su servicio (fase 2), que sabe qué noche bloquea.
     */
    #[Test]
    public function mover_de_casita_no_recicla_el_extra_como_espejo(): void
    {
        [$vieja, $viejaPrincipal, $viejaEspejo] = $this->casita('INTI', 'SAPHY');
        [$nueva, $nuevaPrincipal, $nuevaEspejo] = $this->casita('INTI', 'SAPHY');
        $evento = (new PmsEventoCalendario())->setPmsUnidad($vieja);

        $principal = $this->colgar($evento, $viejaPrincipal, '100')->hacerPrincipal();
        $espejo = $this->colgar($evento, $viejaEspejo, '101');
        $extra = $this->colgar($evento, $viejaEspejo, null)->setRol(PmsEventoBeds24Link::ROL_EXTRA_SALIDA);

        $evento->setPmsUnidad($nueva);
        $this->factory()->hydrateLinksForUi($evento);

        self::assertCount(3, $evento->getBeds24Links());
        self::assertSame($nuevaPrincipal, $principal->getUnidadBeds24Map());
        self::assertSame($nuevaEspejo, $espejo->getUnidadBeds24Map());
        self::assertSame('101', $espejo->getBeds24BookId(), 'mismo virtual: se mueve conservando su reserva');
        self::assertSame($viejaEspejo, $extra->getUnidadBeds24Map(), 'el extra no lo mueve el hidratador');
        self::assertSame(PmsEventoBeds24Link::ROL_EXTRA_SALIDA, $extra->getRol());
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{PmsUnidad, PmsUnidadBeds24Map, PmsUnidadBeds24Map}
     */
    private function casita(string $codigoPrincipal, string $codigoEspejo): array
    {
        $unidad = new PmsUnidad();
        $mapas = [];
        foreach ([[$codigoPrincipal, true], [$codigoEspejo, false]] as [$codigo, $esPrincipal]) {
            $virtual = (new PmsEstablecimientoVirtual())->setCodigo($codigo)->setEsPrincipal($esPrincipal);
            $mapa = (new PmsUnidadBeds24Map())->setVirtualEstablecimiento($virtual)->setActivo(true);
            $unidad->addBeds24Map($mapa);
            $mapas[] = $mapa;
        }

        return [$unidad, $mapas[0], $mapas[1]];
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
