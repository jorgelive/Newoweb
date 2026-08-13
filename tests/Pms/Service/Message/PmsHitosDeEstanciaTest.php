<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Message\Contract\ConversationMilestoneInterface as Hito;
use App\Message\Contract\HitoDeAsunto;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Message\PmsHitosDeEstancia;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los momentos con nombre de una estancia partida.
 *
 * Unitario puro: las entidades del PMS se arman en memoria, sin contenedor ni base de datos.
 * Es justo lo que no tenía el cálculo viejo —`start`/`end` salían de dos agregados y no había
 * forma barata de probar la combinatoria—, y por eso una estancia con hueco llevaba años
 * pasando desapercibida.
 */
final class PmsHitosDeEstanciaTest extends TestCase
{
    private function tramo(PmsReserva $reserva, string $unidad, string $desde, string $hasta, string $estado = PmsEventoEstado::CODIGO_CONFIRMADA): PmsEventoCalendario
    {
        $evento = new PmsEventoCalendario();
        $evento->setInicio(new DateTimeImmutable($desde . ' 14:00'));
        $evento->setFin(new DateTimeImmutable($hasta . ' 10:00'));
        $evento->setEstado($this->estado($estado));
        $evento->setPmsUnidad($this->unidad($unidad));
        $reserva->addEventosCalendario($evento);

        return $evento;
    }

    private function unidad(string $nombre): PmsUnidad
    {
        $unidad = new PmsUnidad();
        $unidad->setNombre($nombre);

        return $unidad;
    }

    private function estado(string $codigo): PmsEventoEstado
    {
        $estado = new PmsEventoEstado();
        $estado->setId($codigo);

        return $estado;
    }

    /** @return list<string> */
    private function tipos(PmsReserva $reserva): array
    {
        return array_map(
            static fn (HitoDeAsunto $h): string => $h->tipo,
            (new PmsHitosDeEstancia())->para($reserva)
        );
    }

    /** Una estancia normal sigue dando lo de siempre: llega y se va. */
    #[Test]
    public function un_solo_tramo_da_llegada_y_salida(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 3', '2026-03-10', '2026-03-15');

        self::assertSame([Hito::START, Hito::END], $this->tipos($reserva));
    }

    /**
     * ⚠️ **La trampa.** El caso más común de reserva con varios tramos no es la estancia
     * partida: es el grupo que ocupa dos casitas LAS MISMAS noches. Comparar tramos
     * consecutivos sin agrupar habría anunciado un cambio de casita a cada familia que reserva
     * dos.
     */
    #[Test]
    public function dos_casitas_a_la_vez_no_son_un_cambio_de_unidad(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 2', '2026-03-10', '2026-03-15');
        $this->tramo($reserva, 'Casita 5', '2026-03-10', '2026-03-15');

        self::assertSame([Hito::START, Hito::END], $this->tipos($reserva));

        $hitos = (new PmsHitosDeEstancia())->para($reserva);
        self::assertSame('Casita 2 + Casita 5', $hitos[0]->detalle, 'el bloque lleva las dos casitas');
    }

    /**
     * El caso que motivó todo: se va a Machu Picchu dos noches y vuelve. Hoy nadie le dice cómo
     * dejar la casita al irse ni le da la bienvenida al volver.
     */
    #[Test]
    public function un_hueco_entre_tramos_da_salida_temporal_y_reingreso(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 1', '2026-03-10', '2026-03-12');
        $this->tramo($reserva, 'Casita 3', '2026-03-15', '2026-03-18');

        self::assertSame(
            [Hito::START, Hito::TEMPORARY_END, Hito::REENTRY, Hito::END],
            $this->tipos($reserva)
        );

        $hitos = (new PmsHitosDeEstancia())->para($reserva);
        self::assertSame('2026-03-12', $hitos[1]->fecha->format('Y-m-d'), 'se va el día que termina el primer tramo');
        self::assertSame('2026-03-15', $hitos[2]->fecha->format('Y-m-d'), 'vuelve el día que empieza el segundo');
        self::assertSame('Casita 3', $hitos[2]->detalle, 'y vuelve a otra casita');
    }

    /** Cambia de casita sin irse: hay que decirle cuál es la nueva y de dónde viene. */
    #[Test]
    public function tramos_seguidos_en_otra_casita_dan_cambio_de_unidad(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 1', '2026-03-10', '2026-03-12');
        $this->tramo($reserva, 'Casita 3', '2026-03-12', '2026-03-15');

        self::assertSame([Hito::START, Hito::UNIT_CHANGE, Hito::END], $this->tipos($reserva));

        $hitos = (new PmsHitosDeEstancia())->para($reserva);
        self::assertSame('Casita 3', $hitos[1]->detalle);
        self::assertSame('Casita 1', $hitos[1]->detalleAnterior, 'para poder redactar «de la 1 a la 3»');
    }

    /** Mismo día y misma casita es una estancia continua partida por administración: no se anuncia. */
    #[Test]
    public function tramos_seguidos_en_la_misma_casita_no_anuncian_nada(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 3', '2026-03-10', '2026-03-12');
        $this->tramo($reserva, 'Casita 3', '2026-03-12', '2026-03-15');

        self::assertSame([Hito::START, Hito::END], $this->tipos($reserva));
    }

    /** Varias escapadas en un viaje largo: por eso los hitos son una lista y no un mapa. */
    #[Test]
    public function dos_huecos_dan_dos_salidas_temporales(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 1', '2026-03-01', '2026-03-04');
        $this->tramo($reserva, 'Casita 1', '2026-03-08', '2026-03-10');
        $this->tramo($reserva, 'Casita 1', '2026-03-14', '2026-03-18');

        self::assertSame(
            [Hito::START, Hito::TEMPORARY_END, Hito::REENTRY, Hito::TEMPORARY_END, Hito::REENTRY, Hito::END],
            $this->tipos($reserva)
        );
    }

    /** Los tramos cancelados no cuentan: si no, inventarían huecos que no existen. */
    #[Test]
    public function los_tramos_cancelados_no_cuentan(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 1', '2026-03-10', '2026-03-15');
        $this->tramo($reserva, 'Casita 9', '2026-03-20', '2026-03-22', PmsEventoEstado::CODIGO_CANCELADA);

        self::assertSame([Hito::START, Hito::END], $this->tipos($reserva));
    }

    /** Sin tramos vivos no hay nada que anunciar, y eso no es un error. */
    #[Test]
    public function una_reserva_sin_tramos_vivos_no_da_hitos(): void
    {
        $reserva = new PmsReserva();
        $this->tramo($reserva, 'Casita 1', '2026-03-10', '2026-03-15', PmsEventoEstado::CODIGO_CANCELADA);

        self::assertSame([], $this->tipos($reserva));
    }
}
