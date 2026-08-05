<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mantiene las EXTENSIONES de una estancia: la noche que un horario extra
 * inutiliza sin venderla.
 *
 * Una entrada temprana deja invendible la víspera y una salida tardía la noche
 * del día de salida: con la casita ocupada a las 09:00 —o entregada a las 17:00—
 * no da tiempo a limpiar. Cada casilla marcada genera un evento hermano en
 * estado `extension` que cubre esa noche.
 *
 * ── Por qué un evento y no una fecha desplazada ─────────────────────────────
 * El primer intento fue estirar `arrival`/`departure` solo en el push. Tenía dos
 * fallos:
 *   1. **No servía para las OTA.** `BookingsPushMappingStrategy` no manda fechas
 *      de una reserva OTA (guard `!$isOta || $isMirror`, §9.4): esa protección
 *      impide que el PMS pise lo que dice el canal, y levantarla habría abierto
 *      un agujero peor que el que se quería tapar.
 *   2. **No protegía del propio PMS.** Beds24 no vendía la noche, pero el
 *      operador sí podía crear una reserva encima desde el calendario, porque
 *      internamente la estancia terminaba a su hora real.
 * Un evento real resuelve las dos: ocupa la unidad dentro del PMS y viaja a
 * Beds24 como `black` — sea la estancia directa o de una OTA.
 *
 * ── Y por qué es INVISIBLE ──────────────────────────────────────────────────
 * La extensión pertenece a la misma reserva (para que el borrado en cascada y
 * las finanzas la sigan), pero no es una estancia: no sale en los calendarios,
 * ni en el listado de eventos, ni entre las estancias del drawer, ni suma en el
 * rollup de la reserva. Todo eso se filtra por `estado = extension`; la lista de
 * sitios está en docs/PmsBeds24ReservasSync.md §7.1.b.
 */
final class PmsExtensionEstanciaService
{
    /** Descripción de la extensión, por tipo. También es lo que se lee en Beds24. */
    private const string DESC_ENTRADA = 'Entrada temprana';
    private const string DESC_SALIDA = 'Salida tardía';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsEventoCalendarioFactory $eventoFactory,
    ) {}

    /**
     * Pone al día las dos extensiones de una estancia según sus casillas.
     *
     * Idempotente: si ya existen y las fechas cuadran, no toca nada; si la
     * estancia se movió, las recoloca; si se desmarca la casilla, las borra.
     * NO hace flush — lo hace quien llama.
     */
    public function sincronizar(PmsEventoCalendario $estancia): void
    {
        // Una extensión no genera extensiones: cortafuegos contra la recursión.
        if ($estancia->esExtension()) {
            return;
        }

        $inicio = $estancia->getInicio();
        $fin = $estancia->getFin();
        if (!$inicio || !$fin || !$estancia->getPmsUnidad()) {
            return;
        }

        // Entrada temprana → la noche ANTERIOR: [inicio-1día, inicio].
        $this->sincronizarUna(
            estancia: $estancia,
            activa: $estancia->isEntradaTemprana(),
            descripcion: self::DESC_ENTRADA,
            desde: $this->menosUnDia($inicio),
            hasta: $inicio,
        );

        // Salida tardía → la noche del día de salida: [fin, fin+1día].
        $this->sincronizarUna(
            estancia: $estancia,
            activa: $estancia->isSalidaTardia(),
            descripcion: self::DESC_SALIDA,
            desde: $fin,
            hasta: $this->masUnDia($fin),
        );
    }

    /** Crea, recoloca o borra UNA extensión. */
    private function sincronizarUna(
        PmsEventoCalendario $estancia,
        bool $activa,
        string $descripcion,
        DateTimeInterface $desde,
        DateTimeInterface $hasta,
    ): void {
        $existente = $this->buscar($estancia, $descripcion);

        if (!$activa) {
            if ($existente !== null) {
                $this->retirar($existente);
            }

            return;
        }

        if ($existente !== null) {
            // La estancia pudo moverse de fechas o de casita después de marcarla.
            $cambioDeCasita = $existente->getPmsUnidad() !== $estancia->getPmsUnidad();

            $existente->setInicio($desde);
            $existente->setFin($hasta);
            $existente->setPmsUnidad($estancia->getPmsUnidad());

            // Y si estaba retirada, REVIVE. Antes se creaba una extensión nueva y
            // la cancelada se quedaba para siempre: marcar y desmarcar tres veces
            // dejaba tres eventos muertos colgando de la reserva. Siempre hay como
            // mucho UNA extensión por tipo y estancia.
            $existente->setEstado(
                $this->em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_EXTENSION)
            );

            // Los links SÓLO se rehacen si cambió la casita: son los del mapa de la
            // anterior y ya no valen. Llamarlo siempre reventaba al revivir una
            // extensión en el mismo proceso —«A managed+dirty entity Link … can not
            // be scheduled for insertion»—, porque la fábrica intenta insertar links
            // que el UnitOfWork ya está gestionando.
            if ($cambioDeCasita) {
                $this->eventoFactory->rebuildLinks($existente);
            }

            return;
        }

        $extension = $this->crear($estancia, $descripcion, $desde, $hasta);

        // Sin LINK no hay push: la cola se arma sobre `PmsEventoBeds24Link`, así que
        // una extensión sin hidratar se quedaría en el PMS sin bloquear nada en el
        // canal — que es justo para lo que existe. La fábrica resuelve los mapas
        // activos de la unidad y crea los links (§6.2).
        $this->eventoFactory->hydrateLinksForUi($extension);

        $this->em->persist($extension);
    }

    /**
     * Retira una extensión que ya no hace falta.
     *
     * **Se CANCELA, nunca se borra.** Dos razones, y la segunda no es teórica:
     *
     * 1. Es la regla del dominio: `getMotivoNoBorrable()` ya prohíbe borrar un
     *    evento que existe en Beds24 (link con `beds24BookId`) — hay que pasarlo
     *    a «cancelada» y dejar que la sincronización lo retire allí. Borrarlo
     *    dejaría la noche bloqueada en el canal para siempre.
     * 2. Borrar aquí revienta el flush. Este servicio corre dentro de un
     *    `postFlush`, y al eliminar el evento se van en cascada sus
     *    `PmsEventoBeds24Link`; el listener de push los recoge como «borrados»
     *    para encolar el DELETE, y al construir la cola con un link ya eliminado
     *    Doctrine intenta revivirlo: «A new entity was found through the
     *    relationship PmsEventoBeds24Link#evento». Cancelar es un UPDATE simple
     *    que no toca links y que el push traduce a `cancelled`.
     *
     * Consecuencia visible: la extensión retirada no desaparece de la base, queda
     * como evento CANCELADO de una noche. Los calendarios que ocultan canceladas
     * no la muestran; el de «Todas» sí, y ahí es información honesta — dice que
     * esa noche estuvo bloqueada y se liberó.
     */
    private function retirar(PmsEventoCalendario $extension): void
    {
        $extension->setEstado(
            $this->em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CANCELADA)
        );
    }

    private function crear(
        PmsEventoCalendario $estancia,
        string $descripcion,
        DateTimeInterface $desde,
        DateTimeInterface $hasta,
    ): PmsEventoCalendario {
        $extension = new PmsEventoCalendario();
        $extension->setEventoOrigen($estancia);
        $extension->setReserva($estancia->getReserva());
        $extension->setPmsUnidad($estancia->getPmsUnidad());
        $extension->setInicio($desde);
        $extension->setFin($hasta);
        $extension->setEstado($this->em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_EXTENSION));

        // `estado_pago_id` es NOT NULL. Va «no pagado» y ahí se queda: la extensión
        // no cobra nada por sí misma —lo que se cobre por el horario extra vive en
        // el cargo de la estancia— y el listener de estado de pago no la mira,
        // porque el rollup financiero la excluye.
        $extension->setEstadoPago($this->em->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO));

        // SIEMPRE canal directo y `isOta = false`, aunque la estancia venga de
        // Airbnb: la extensión la inventa el PMS, y si se marcara como OTA el
        // push se negaría a mandar sus fechas (§9.4) y no bloquearía nada.
        $extension->setChannel($this->em->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO));
        $extension->setIsOta(false);

        // Sin pax: no duerme nadie. Si contara huéspedes, el rollup de la reserva
        // los sumaría a los de la estancia.
        $extension->setCantidadAdultos(0);
        $extension->setCantidadNinos(0);

        $localizador = $estancia->getReserva()?->getLocalizador();
        $extension->setDescripcion($descripcion . ($localizador ? ' · ' . $localizador : ''));

        return $extension;
    }

    /**
     * La extensión de esta estancia y de este tipo, si existe.
     *
     * Se busca por `eventoOrigen` + descripción y NO por fechas: la estancia
     * puede haberse movido después de marcarla, y entonces las fechas de la
     * extensión ya no cuadran con las nuevas — que es justo el caso en el que
     * hay que recolocarla, no crear otra.
     */
    private function buscar(PmsEventoCalendario $estancia, string $descripcion): ?PmsEventoCalendario
    {
        // `findBy` y NO un `createQueryBuilder()->setParameter('origen', $estancia)`:
        // el id es un UUID BINARY(16) y en DQL ese parámetro se serializa mal — la
        // consulta no falla, devuelve CERO filas. Aquí el síntoma era que al
        // desmarcar una casilla no encontraba la extensión para borrarla y encima
        // duplicaba la otra. Es la misma trampa de §12.6 del doc y de
        // `PmsEventosSpaCalendarProvider::fetchFinanzas()`: el persister de
        // `findBy()` sí conoce el tipo de la columna destino.
        $extensiones = $this->em->getRepository(PmsEventoCalendario::class)
            ->findBy(['eventoOrigen' => $estancia]);

        foreach ($extensiones as $extension) {
            // Las canceladas TAMBIÉN cuentan: se reviven en vez de crear otra
            // (ver `sincronizarUna`).
            if (str_starts_with((string) $extension->getDescripcion(), $descripcion)) {
                return $extension;
            }
        }

        return null;
    }

    private function menosUnDia(DateTimeInterface $dt): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dt)->modify('-1 day');
    }

    private function masUnDia(DateTimeInterface $dt): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($dt)->modify('+1 day');
    }
}
