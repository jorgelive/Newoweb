<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Contract\ConversationMilestoneInterface as Hito;
use App\Message\Contract\HitoDeAsunto;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Operacion\Service\OperacionHitosDeViaje;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los mini-hitos de un itinerario.
 *
 * Un viaje es todo días de en medio: al pasajero no le sirve saber cuándo empieza, le sirve
 * saber a qué hora le recogen mañana. Esta es la misma forma —lista de hitos con tipo y fecha—
 * que resolvió la estancia partida en alojamiento, aplicada a los servicios operativos.
 */
final class OperacionHitosDeViajeTest extends TestCase
{
    private function servicio(
        string $descripcion,
        string $fecha,
        ?string $hora = null,
        EstadoOperacionEnum $estado = EstadoOperacionEnum::PENDIENTE,
    ): OperacionServicio {
        $servicio = new OperacionServicio();
        $servicio->setDescripcionServicio($descripcion);
        $servicio->setFechaServicio(new DateTimeImmutable($fecha));
        $servicio->setHoraRecojoReal($hora);
        $servicio->setEstadoOperacion($estado);

        return $servicio;
    }

    /** @param list<OperacionServicio> $servicios @return list<string> */
    private function tipos(array $servicios): array
    {
        return array_map(
            static fn (HitoDeAsunto $h): string => $h->tipo,
            (new OperacionHitosDeViaje())->para($servicios)
        );
    }

    /** El caso real: un itinerario de varios días con sus servicios sueltos por medio. */
    #[Test]
    public function cada_servicio_de_en_medio_es_un_hito_propio(): void
    {
        $hitos = (new OperacionHitosDeViaje())->para([
            $this->servicio('Traslado del aeropuerto', '2026-05-01'),
            $this->servicio('City tour con guía', '2026-05-02'),
            $this->servicio('Bus a Puno', '2026-05-04'),
            $this->servicio('Traslado de salida', '2026-05-08'),
        ]);

        self::assertSame(
            [Hito::START, Hito::SERVICE, Hito::SERVICE, Hito::END],
            array_map(static fn (HitoDeAsunto $h): string => $h->tipo, $hitos)
        );
        self::assertSame('Bus a Puno', $hitos[2]->detalle, 'el detalle es lo que se le puede leer al pasajero');
    }

    /** Llegan en cualquier orden desde la consulta; el hito manda es la fecha. */
    #[Test]
    public function se_ordenan_por_fecha_aunque_lleguen_desordenados(): void
    {
        $hitos = (new OperacionHitosDeViaje())->para([
            $this->servicio('Bus a Puno', '2026-05-04'),
            $this->servicio('Traslado del aeropuerto', '2026-05-01'),
            $this->servicio('Traslado de salida', '2026-05-08'),
        ]);

        self::assertSame('Traslado del aeropuerto', $hitos[0]->detalle);
        self::assertSame('Traslado de salida', $hitos[2]->detalle);
    }

    /** La hora de recojo es el dato que de verdad se le manda: «mañana te recogen a las 06:15». */
    #[Test]
    public function la_hora_de_recojo_entra_en_el_hito(): void
    {
        $hitos = (new OperacionHitosDeViaje())->para([
            $this->servicio('Machu Picchu', '2026-05-03', '06:15'),
        ]);

        self::assertSame('2026-05-03 06:15', $hitos[0]->fecha->format('Y-m-d H:i'));
    }

    /**
     * `horaRecojoReal` es texto libre que teclea el operador. Si no se puede leer se deja el día
     * a secas: un mensaje a una hora rara se recupera, uno el día equivocado no.
     */
    #[Test]
    public function una_hora_ilegible_deja_el_dia_a_secas(): void
    {
        $hitos = (new OperacionHitosDeViaje())->para([
            $this->servicio('Excursión', '2026-05-03', 'por confirmar'),
        ]);

        self::assertSame('2026-05-03 00:00', $hitos[0]->fecha->format('Y-m-d H:i'));
    }

    /** Un viaje de un solo servicio empieza y acaba en él: las dos reglas deben poder disparar. */
    #[Test]
    public function un_servicio_unico_es_principio_y_fin(): void
    {
        self::assertSame(
            [Hito::START, Hito::END],
            $this->tipos([$this->servicio('Traslado', '2026-05-01')])
        );
    }

    #[Test]
    public function los_servicios_cancelados_no_cuentan(): void
    {
        self::assertSame(
            [Hito::START, Hito::END],
            $this->tipos([
                $this->servicio('Traslado', '2026-05-01'),
                $this->servicio('Excursión anulada', '2026-05-03', null, EstadoOperacionEnum::CANCELADO),
                $this->servicio('Salida', '2026-05-05'),
            ])
        );
    }

    /** Una fila de La Biblia a medio rellenar no revienta nada: se salta y ya. */
    #[Test]
    public function un_servicio_sin_fecha_se_salta(): void
    {
        $sinFecha = new OperacionServicio();
        $sinFecha->setDescripcionServicio('Pendiente de cuadrar');

        self::assertSame(
            [Hito::START, Hito::END],
            $this->tipos([$this->servicio('Traslado', '2026-05-01'), $sinFecha])
        );
    }

    /** Mientras el viaje sólo está cotizado no hay servicios: no hay hitos, y no es un error. */
    #[Test]
    public function sin_servicios_vivos_no_hay_hitos(): void
    {
        self::assertSame([], $this->tipos([]));
    }
}
