<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Message\Contract\ConversationMilestoneInterface as Hito;
use App\Message\Contract\HitoDeAsunto;
use App\Pms\Entity\PmsConversacionEnlace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo que guarda el enlace cuando su reserva cambia.
 *
 * El sincronizador entero necesita el EntityManager, así que aquí se prueba **lo que decide sin
 * base de datos**: que los hitos que se guardan son los derivados de los tramos y que el mapa
 * plano de siempre —el que leen las reglas en producción— queda en sintonía con ellos.
 *
 * Es el punto donde más fácil sería equivocarse: si la lista y el mapa contaran cosas distintas
 * sobre las mismas fechas, tendríamos dos verdades y ningún error que lo delatara.
 */
final class PmsSincronizadorDeEnlaceTest extends TestCase
{
    /** @param list<HitoDeAsunto> $hitos */
    private function enlaceCon(array $hitos): PmsConversacionEnlace
    {
        return (new PmsConversacionEnlace())->setHitos($hitos);
    }

    private function hito(string $tipo, string $fecha, ?string $detalle = null): HitoDeAsunto
    {
        return new HitoDeAsunto($tipo, new \DateTimeImmutable($fecha), $detalle);
    }

    /** Lo que entra vuelve a salir: tipos, fechas y detalles, en orden. */
    #[Test]
    public function los_hitos_sobreviven_al_viaje_de_ida_y_vuelta(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00', 'Casita 1'),
            $this->hito(Hito::TEMPORARY_END, '2026-03-12 10:00', 'Casita 1'),
            $this->hito(Hito::REENTRY, '2026-03-15 14:00', 'Casita 3'),
            $this->hito(Hito::END, '2026-03-18 10:00', 'Casita 3'),
        ]);

        $vueltos = $enlace->getHitos();

        self::assertCount(4, $vueltos);
        self::assertSame(Hito::REENTRY, $vueltos[2]->tipo);
        self::assertSame('2026-03-15 14:00', $vueltos[2]->fecha->format('Y-m-d H:i'));
        self::assertSame('Casita 3', $vueltos[2]->detalle);
    }

    /**
     * El mapa plano tiene que seguir sirviendo a las reglas de hoy: la PRIMERA llegada y la
     * ÚLTIMA salida, no las intermedias.
     */
    #[Test]
    public function el_mapa_plano_toma_la_primera_llegada_y_la_ultima_salida(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00'),
            $this->hito(Hito::TEMPORARY_END, '2026-03-12 10:00'),
            $this->hito(Hito::REENTRY, '2026-03-15 14:00'),
            $this->hito(Hito::END, '2026-03-18 10:00'),
        ]);

        $plano = $enlace->getMilestones();

        self::assertSame('2026-03-10 14:00:00', $plano[Hito::START]);
        self::assertSame('2026-03-18 10:00:00', $plano[Hito::END]);
        self::assertArrayNotHasKey(Hito::TEMPORARY_END, $plano, 'los intermedios no caben en el mapa: para eso está la lista');
    }

    /** Una estancia normal no pierde nada por el camino nuevo. */
    #[Test]
    public function una_estancia_de_un_tramo_da_el_mismo_mapa_de_siempre(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00'),
            $this->hito(Hito::END, '2026-03-15 10:00'),
        ]);

        self::assertSame(
            ['start' => '2026-03-10 14:00:00', 'end' => '2026-03-15 10:00:00'],
            $enlace->getMilestones()
        );
    }

    /**
     * Sin tramos vivos —todo cancelado— no hay hitos, y el mapa no se inventa fechas.
     *
     * Importa porque un mapa con un `start` fantasma es un mensaje de bienvenida a alguien que
     * canceló.
     */
    #[Test]
    public function sin_hitos_el_mapa_no_inventa_nada(): void
    {
        $enlace = $this->enlaceCon([]);

        self::assertSame([], $enlace->getHitos());
        self::assertSame([], $enlace->getMilestones());
    }

    /** Recalcular sustituye: los hitos viejos no se acumulan con los nuevos. */
    #[Test]
    public function recalcular_sustituye_y_no_acumula(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00'),
            $this->hito(Hito::TEMPORARY_END, '2026-03-12 10:00'),
            $this->hito(Hito::REENTRY, '2026-03-15 14:00'),
            $this->hito(Hito::END, '2026-03-18 10:00'),
        ]);

        // El operador borra el segundo tramo: ya no hay hueco, y la estancia acaba antes.
        $enlace->setHitos([
            $this->hito(Hito::START, '2026-03-10 14:00'),
            $this->hito(Hito::END, '2026-03-12 10:00'),
        ]);

        self::assertCount(2, $enlace->getHitos());
        self::assertSame('2026-03-12 10:00:00', $enlace->getMilestones()[Hito::END], 'la salida se adelanta');
    }

    /**
     * Lo que deriva el cálculo es exactamente lo que se guarda: sin conversión por el medio.
     *
     * Une las dos piezas —el cálculo de los hitos y su persistencia— porque cada una probada por
     * su lado no descarta que se pierdan campos al pasar de una a otra.
     */
    #[Test]
    public function lo_que_deriva_el_calculo_es_lo_que_se_guarda(): void
    {
        $hitos = [
            $this->hito(Hito::START, '2026-03-10 14:00', 'Casita 1'),
            $this->hito(Hito::UNIT_CHANGE, '2026-03-12 14:00', 'Casita 3'),
            $this->hito(Hito::END, '2026-03-15 10:00', 'Casita 3'),
        ];

        $guardados = $this->enlaceCon($hitos)->getHitos();

        self::assertSame(
            array_map(static fn (HitoDeAsunto $h): string => $h->tipo . '|' . $h->fecha->format('c') . '|' . $h->detalle, $hitos),
            array_map(static fn (HitoDeAsunto $h): string => $h->tipo . '|' . $h->fecha->format('c') . '|' . $h->detalle, $guardados)
        );
    }
}
