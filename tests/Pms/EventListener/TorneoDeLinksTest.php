<?php

declare(strict_types=1);

namespace App\Tests\Pms\EventListener;

use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsUnidadBeds24Map;
use App\Pms\EventListener\Queue\Beds24BookingsPushQueueListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El torneo del push: un ganador por (evento, mapa, ROL).
 *
 * La `black` de la víspera de una entrada temprana vive en el MISMO mapa que la reserva de la
 * estancia. Sin el rol en la clave competían entre sí, ganaba el principal y el push de la
 * `black` se cancelaba: la noche extra no habría llegado nunca a Beds24.
 */
final class TorneoDeLinksTest extends TestCase
{
    #[Test]
    public function la_black_extra_y_la_reserva_del_mismo_mapa_salen_las_dos(): void
    {
        $evento = new PmsEventoCalendario();
        $mapa = new PmsUnidadBeds24Map();

        $principal = $this->link($evento, $mapa, '100')->hacerPrincipal();
        $extra = $this->link($evento, $mapa, '200')->setRol(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);

        self::assertSame(
            ['100' => 'PUSH', '200' => 'PUSH'],
            $this->acciones(Beds24BookingsPushQueueListener::torneo([$principal, $extra]))
        );
    }

    /** Lo que el torneo siempre hizo: dos candidatos a la MISMA reserva, gana el que tiene id. */
    #[Test]
    public function dos_links_del_mismo_rol_y_mapa_siguen_compitiendo(): void
    {
        $evento = new PmsEventoCalendario();
        $mapa = new PmsUnidadBeds24Map();

        $conId = $this->link($evento, $mapa, '300');
        $sinId = $this->link($evento, $mapa, null);

        $tareas = Beds24BookingsPushQueueListener::torneo([$sinId, $conId]);

        self::assertSame('PUSH', $this->accionDe($tareas, $conId));
        self::assertSame('CANCEL', $this->accionDe($tareas, $sinId));
    }

    #[Test]
    public function dos_extras_del_mismo_rol_compiten(): void
    {
        $evento = new PmsEventoCalendario();
        $mapa = new PmsUnidadBeds24Map();

        $a = $this->link($evento, $mapa, '400')->setRol(PmsEventoBeds24Link::ROL_EXTRA_SALIDA);
        $b = $this->link($evento, $mapa, null)->setRol(PmsEventoBeds24Link::ROL_EXTRA_SALIDA);

        $tareas = Beds24BookingsPushQueueListener::torneo([$a, $b]);

        self::assertSame('PUSH', $this->accionDe($tareas, $a));
        self::assertSame('CANCEL', $this->accionDe($tareas, $b));
    }

    #[Test]
    public function un_link_sin_mapa_se_cancela(): void
    {
        $zombi = (new PmsEventoBeds24Link())->setEvento(new PmsEventoCalendario());

        self::assertSame('CANCEL', $this->accionDe(Beds24BookingsPushQueueListener::torneo([$zombi]), $zombi));
    }

    // ── Andamiaje ─────────────────────────────────────────────────────────────────────────

    private function link(PmsEventoCalendario $evento, PmsUnidadBeds24Map $mapa, ?string $bookId): PmsEventoBeds24Link
    {
        return (new PmsEventoBeds24Link())
            ->setEvento($evento)
            ->setUnidadBeds24Map($mapa)
            ->setBeds24BookId($bookId);
    }

    /**
     * @param list<array{link: PmsEventoBeds24Link, action: string}> $tareas
     *
     * @return array<string, string>
     */
    private function acciones(array $tareas): array
    {
        $porId = [];
        foreach ($tareas as $tarea) {
            $porId[(string) $tarea['link']->getBeds24BookId()] = $tarea['action'];
        }
        ksort($porId);

        return $porId;
    }

    /**
     * @param list<array{link: PmsEventoBeds24Link, action: string}> $tareas
     */
    private function accionDe(array $tareas, PmsEventoBeds24Link $link): ?string
    {
        foreach ($tareas as $tarea) {
            if ($tarea['link'] === $link) {
                return $tarea['action'];
            }
        }

        return null;
    }
}
