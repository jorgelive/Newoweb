<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Contract\MapaDeHitos;
use App\Contract\MomentoDeHito;
use App\Contract\ConversationMilestoneInterface as Hito;
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

        self::assertSame('2026-03-10T14:00:00', $plano[Hito::START]);
        self::assertSame('2026-03-18T10:00:00', $plano[Hito::END]);
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
            ['start' => '2026-03-10T14:00:00', 'end' => '2026-03-15T10:00:00'],
            $enlace->getMilestones()
        );
    }

    /**
     * ⚠️ El fallo que se coló: `start` se escribía una sola vez en la vida del enlace.
     *
     * La guarda era `!isset($plano['start'])` sobre el mapa **existente**, así que mover la
     * llegada dejaba la lista diciendo la fecha nueva y el mapa la vieja. Como el motor programa
     * con el mapa, el check-in salía calculado contra la fecha vieja: exactamente el síntoma que
     * este trabajo venía a matar, reintroducido por una guarda de más.
     */
    #[Test]
    public function mover_la_llegada_actualiza_el_mapa_y_no_solo_la_lista(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00'),
            $this->hito(Hito::END, '2026-03-15 10:00'),
        ]);

        $enlace->setHitos([
            $this->hito(Hito::START, '2026-03-12 14:00'),
            $this->hito(Hito::END, '2026-03-15 10:00'),
        ]);

        self::assertSame('2026-03-12T14:00:00', $enlace->getMilestones()[Hito::START]);
    }

    /** Y si se queda sin tramos vivos, no puede sobrevivir un `start` de cuando los tenía. */
    #[Test]
    public function quedarse_sin_hitos_borra_el_start_anterior(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00'),
            $this->hito(Hito::END, '2026-03-15 10:00'),
        ]);

        $enlace->setHitos([]);

        self::assertArrayNotHasKey(Hito::START, $enlace->getMilestones());
        self::assertArrayNotHasKey(Hito::END, $enlace->getMilestones());
    }

    /**
     * Las claves que NO salen de los tramos se respetan.
     *
     * `created_at` y `expected_arrival` los pone el contexto, y hay reglas colgadas de los dos
     * —la bienvenida y el aviso previo a la llegada—. Si `setHitos()` los borrase, toda reserva
     * nueva se quedaría sin bienvenida y sin que nada lo delatara.
     */
    #[Test]
    public function los_hitos_que_no_salen_de_los_tramos_sobreviven(): void
    {
        $enlace = (new PmsConversacionEnlace())
            ->setMilestones(MapaDeHitos::desdeCrudo([Hito::CREATED => '2026-03-01T09:00:00', Hito::EXPECTED_ARRIVAL => '2026-03-10T18:00:00']))
            ->setHitos([
                $this->hito(Hito::START, '2026-03-10 14:00'),
                $this->hito(Hito::END, '2026-03-15 10:00'),
            ]);

        $plano = $enlace->getMilestones();

        self::assertSame('2026-03-01T09:00:00', $plano[Hito::CREATED]);
        self::assertSame('2026-03-10T18:00:00', $plano[Hito::EXPECTED_ARRIVAL]);
        self::assertSame('2026-03-10T14:00:00', $plano[Hito::START]);
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

    /**
     * Cancelar MARCA, no borra. El asunto sigue en el hilo, con su fecha de cancelación.
     *
     * Se borraba, y era un error con dos caras: se perdía el rastro de lo que le pasó a esa
     * persona, y se cerraba la puerta a avisar de la cancelación — `AgendaDeAsunto::estaCancelada()`
     * lee este hito **del enlace**, así que sin enlace esa rama era código muerto de hecho.
     */
    #[Test]
    public function marcar_cancelado_deja_constancia_sin_borrar_los_hitos(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00', 'Casita 1'),
            $this->hito(Hito::END, '2026-03-15 10:00', 'Casita 1'),
        ]);

        $enlace->marcarCancelado(MomentoDeHito::de('2026-03-05 11:30:00'));

        self::assertTrue($enlace->estaCancelado());
        self::assertSame('2026-03-05T11:30:00', $enlace->getMilestones()[Hito::CANCELLED]);
        self::assertCount(2, $enlace->getHitos(), 'lo que iba a pasar sigue ahí: es la historia de esa persona');
    }

    /** Sin fecha explícita se sella el momento, para que el hito nunca quede vacío. */
    #[Test]
    public function marcar_cancelado_sin_fecha_sella_el_momento(): void
    {
        $enlace = (new PmsConversacionEnlace())->marcarCancelado();

        self::assertTrue($enlace->estaCancelado());
        self::assertNotSame('', $enlace->getMilestones()[Hito::CANCELLED]);
    }

    /** Un enlace normal no está cancelado: la marca tiene que ser explícita. */
    #[Test]
    public function un_enlace_sin_marcar_no_esta_cancelado(): void
    {
        self::assertFalse($this->enlaceCon([$this->hito(Hito::START, '2026-03-10 14:00')])->estaCancelado());
    }

    /**
     * ⚠️ Y recalcular los hitos NO borra la marca: `setHitos()` sólo reescribe `start`/`end`.
     *
     * Importa porque el sincronizador recalcula en cada cambio de la reserva, y si eso limpiara
     * la cancelación, un asunto muerto volvería a estar vivo para el motor sin que nadie lo
     * pidiera.
     */
    #[Test]
    public function recalcular_los_hitos_no_resucita_un_asunto_cancelado(): void
    {
        $enlace = (new PmsConversacionEnlace())->marcarCancelado(MomentoDeHito::de('2026-03-05 11:30:00'));

        $enlace->setHitos([$this->hito(Hito::START, '2026-03-10 14:00')]);

        self::assertTrue($enlace->estaCancelado(), 'la cancelación sobrevive al recálculo');
    }

    /**
     * Pierde una casita de dos: la reserva sigue viva y hay algo concreto que contar.
     *
     * Es el caso que era invisible: los hitos se recalculaban en silencio y el huésped no se
     * enteraba de que una casita había dejado de ser suya.
     */
    #[Test]
    public function perder_una_casita_de_dos_se_anota_como_cancelacion_parcial(): void
    {
        $enlace = $this->enlaceCon([
            $this->hito(Hito::START, '2026-03-10 14:00', 'Casita 2 + Casita 5'),
            $this->hito(Hito::END, '2026-03-15 10:00', 'Casita 2 + Casita 5'),
        ]);

        self::assertSame(['Casita 2', 'Casita 5'], $enlace->unidadesDerivadas());

        // Se cae la 5: el recálculo deja sólo la 2.
        $enlace->setHitos([
            $this->hito(Hito::START, '2026-03-10 14:00', 'Casita 2'),
            $this->hito(Hito::END, '2026-03-15 10:00', 'Casita 2'),
        ]);
        $enlace->anotarCancelacionParcial('Casita 5');

        $tipos = array_map(static fn (HitoDeAsunto $h): string => $h->tipo, $enlace->getHitos());

        self::assertContains(Hito::PARTIAL_CANCELLATION, $tipos);
        self::assertArrayHasKey(Hito::PARTIAL_CANCELLATION, $enlace->getMilestones(), 'una regla puede colgarse de esto ya');
    }

    /**
     * ⚠️ La trampa: una cancelación parcial es un HECHO, no un estado derivado.
     *
     * Los tramos que la provocaron ya no existen, así que no se puede volver a deducir. Si
     * `setHitos()` la borrara, desaparecería en cuanto la reserva volviera a tocarse —y el aviso
     * que la anuncia se quedaría sin fecha a la que colgarse—.
     */
    #[Test]
    public function la_cancelacion_parcial_sobrevive_a_los_recalculos(): void
    {
        $enlace = $this->enlaceCon([$this->hito(Hito::START, '2026-03-10 14:00', 'Casita 2')]);
        $enlace->anotarCancelacionParcial('Casita 5');

        // El operador mueve las fechas: recálculo completo de los derivados.
        $enlace->setHitos([
            $this->hito(Hito::START, '2026-03-12 14:00', 'Casita 2'),
            $this->hito(Hito::END, '2026-03-16 10:00', 'Casita 2'),
        ]);

        $tipos = array_map(static fn (HitoDeAsunto $h): string => $h->tipo, $enlace->getHitos());

        self::assertContains(Hito::PARTIAL_CANCELLATION, $tipos, 'el hecho ocurrido no se recalcula: se conserva');
        self::assertSame('2026-03-12T14:00:00', $enlace->getMilestones()[Hito::START], 'y los derivados sí se rehacen');
    }

    /**
     * ⚠️ Y la trampa de la trampa: la casita perdida NO puede contar como presente.
     *
     * `unidadesDerivadas()` ignora las cancelaciones parciales ya anotadas. Si las contara, la
     * casita perdida volvería a aparecer como cubierta y el siguiente recálculo la daría por
     * perdida otra vez, anotando un duplicado **en cada guardado de la reserva**.
     */
    #[Test]
    public function una_casita_ya_perdida_no_vuelve_a_contarse_como_presente(): void
    {
        $enlace = $this->enlaceCon([$this->hito(Hito::START, '2026-03-10 14:00', 'Casita 2')]);
        $enlace->anotarCancelacionParcial('Casita 5');

        self::assertSame(['Casita 2'], $enlace->unidadesDerivadas(), 'la 5 ya no cuenta como cubierta');
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
        self::assertSame('2026-03-12T10:00:00', $enlace->getMilestones()[Hito::END], 'la salida se adelanta');
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
