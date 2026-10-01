<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La noche extra se deriva de la casilla: `PmsEventoCalendario::nocheExtra()` es su única fuente.
 * Ver docs/PlanHorarioExtraSinEventos.md.
 */
final class NocheExtraTest extends TestCase
{
    /** Por días: entrar a las 09:00 del 02/02 deja sin vender la noche del 01/02. */
    #[Test]
    public function la_entrada_temprana_es_la_vispera_aunque_entre_de_madrugada(): void
    {
        $noche = $this->estancia('2027-02-02 09:00', '2027-02-05 10:00', entrada: true)
            ->nocheExtra(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA);

        self::assertNotNull($noche);
        self::assertSame(['2027-02-01 00:00', '2027-02-02 00:00'], [$noche->desde->format('Y-m-d H:i'), $noche->hasta->format('Y-m-d H:i')]);
        self::assertSame('Entrada temprana', $noche->etiqueta());
    }

    #[Test]
    public function la_salida_tardia_es_la_noche_del_dia_de_salida(): void
    {
        $noche = $this->estancia('2027-02-02 14:00', '2027-02-05 17:00', salida: true)
            ->nocheExtra(PmsEventoBeds24Link::ROL_EXTRA_SALIDA);

        self::assertNotNull($noche);
        self::assertSame(['2027-02-05', '2027-02-06'], [$noche->desde->format('Y-m-d'), $noche->hasta->format('Y-m-d')]);
        self::assertSame('Salida tardía', $noche->etiqueta());
    }

    #[Test]
    public function sin_casilla_no_hay_noche(): void
    {
        $estancia = $this->estancia('2027-02-02 14:00', '2027-02-05 10:00', entrada: true);

        self::assertNull($estancia->nocheExtra(PmsEventoBeds24Link::ROL_EXTRA_SALIDA));
        self::assertCount(1, $estancia->nochesExtra());
    }

    /**
     * Cancelada o inquiry: no retiene la casita, tampoco su noche. La casilla sigue marcada para
     * que reactivar la estancia sea lo que la devuelva.
     */
    #[Test]
    public function una_estancia_que_no_retiene_la_casita_no_tiene_noche_extra(): void
    {
        foreach ([PmsEventoEstado::CODIGO_CANCELADA, PmsEventoEstado::CODIGO_ABIERTO] as $estado) {
            $estancia = $this->estancia('2027-02-02 14:00', '2027-02-05 10:00', entrada: true, salida: true, estado: $estado);

            self::assertSame([], $estancia->nochesExtra(), $estado);
            self::assertTrue($estancia->isEntradaTemprana(), 'la casilla no se toca');
        }
    }

    /** Sigue a la estancia: moverla mueve la noche, sin nadie que la recoloque. */
    #[Test]
    public function mover_la_estancia_mueve_la_noche(): void
    {
        $estancia = $this->estancia('2027-02-02 14:00', '2027-02-05 10:00', entrada: true);
        $estancia->setInicio(new DateTimeImmutable('2027-02-10 14:00'))->setFin(new DateTimeImmutable('2027-02-12 10:00'));

        self::assertSame('2027-02-09', $estancia->nocheExtra(PmsEventoBeds24Link::ROL_EXTRA_ENTRADA)?->desde->format('Y-m-d'));
    }

    #[Test]
    public function el_rol_de_la_estancia_no_es_una_noche(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->estancia('2027-02-02 14:00', '2027-02-05 10:00')->nocheExtra(PmsEventoBeds24Link::ROL_ESTANCIA);
    }

    private function estancia(
        string $inicio,
        string $fin,
        bool $entrada = false,
        bool $salida = false,
        string $estado = PmsEventoEstado::CODIGO_CONFIRMADA,
    ): PmsEventoCalendario {
        return (new PmsEventoCalendario())
            ->setInicio(new DateTimeImmutable($inicio))
            ->setFin(new DateTimeImmutable($fin))
            ->setEntradaTemprana($entrada)
            ->setSalidaTardia($salida)
            ->setEstado(new PmsEventoEstado($estado));
    }
}
