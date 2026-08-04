<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Calendar\Service\CalendarResourceCatalog;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Variante de PmsEventosRawCalendarProvider para consumidores API/SPA (Vue).
 *
 * Diferencias respecto al provider legacy (EasyAdmin):
 * - No genera urledit/urlshow (no depende de rutas de EasyAdmin ni del router de Symfony).
 * - No depende de runtime_returnTo / current_page.
 * - No hace chequeos de ROLE_* para decidir si expone un link (eso es responsabilidad
 *   de la API REST real que consuma el id, no del calendario).
 * - Expone "context" + ids crudos en extendedProps para que el frontend arme su propia
 *   navegación (reserva vs bloqueo directo).
 */
final class PmsEventosSpaCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly CalendarResourceCatalog $resourceCatalog,
    ) {}

    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'pms_eventos_spa');
    }

    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $eventos = $this->fetchEventos($from, $to, $config);
        $finanzas = $this->fetchFinanzas($eventos);
        $out = [];

        foreach ($eventos as $evento) {
            if (!$evento instanceof PmsEventoCalendario) continue;

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();
            $unidad = $evento->getPmsUnidad();

            if (!$inicio || !$fin || !$unidad) continue;

            $reserva = $evento->getReserva();
            $estado = $evento->getEstado();
            $estadoPago = $evento->getEstadoPago();

            $out[] = new CalendarEventDto(
                id: $evento->getId() ?? spl_object_id($evento),
                title: $this->buildTitle($evento, $reserva),
                start: $inicio,
                end: $fin,
                resourceId: $unidad->getId(),
                backgroundColor: $this->resolveColor($estado, $estadoPago),
                tooltip: $this->buildTooltip($evento, $reserva),
                extendedProps: $this->buildContext(
                    $evento,
                    $reserva,
                    $finanzas[(string) $reserva?->getId()] ?? null,
                ),
            );
        }

        return $out;
    }

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $eventos = $this->fetchEventos($from, $to, $config);
        $seen = [];
        $out = [];

        foreach ($eventos as $evento) {
            $unidad = $evento->getPmsUnidad();
            $id = $unidad?->getId();
            if ($id === null) continue;

            $idStr = (string) $id;
            if (isset($seen[$idStr])) continue;

            $seen[$idStr] = true;
            $out[] = new CalendarResourceDto(id: $idStr, title: (string) $unidad);
        }

        // Las unidades sin eventos en el rango desaparecían de la grilla: el
        // catálogo las repone (ver resources.showAll en el YAML) y se encarga
        // del orden natural + índice `orden`.
        return $this->resourceCatalog->merge($out, $config, PmsUnidad::class);
    }

    private function fetchEventos(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $em = $this->managerRegistry->getManagerForClass(PmsEventoCalendario::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new HttpException(500, 'EntityManager no disponible.');
        }

        $filters = (array) ($config['filters'] ?? []);

        $qb = $em->createQueryBuilder()
            ->select('e, u, r, es, ep')
            ->from(PmsEventoCalendario::class, 'e')
            ->leftJoin('e.pmsUnidad', 'u')
            ->leftJoin('e.reserva', 'r')
            ->leftJoin('e.estado', 'es')
            ->leftJoin('e.estadoPago', 'ep')
            ->andWhere('e.inicio < :to AND e.fin > :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $this->applyIdFilter($qb, 'es', 'estado', $filters);
        $this->applyIdFilter($qb, 'ep', 'estadoPago', $filters);

        return $qb->getQuery()->getResult();
    }

    private function applyIdFilter(QueryBuilder $qb, string $alias, string $key, array $filters): void
    {
        $val = $filters[$key] ?? null;
        if (empty($val)) return;

        if (is_array($val) && (isset($val['in']) || isset($val['not_in']))) {
            if (!empty($val['in'])) {
                $qb->andWhere("$alias.id IN (:$key" . "_in)")->setParameter($key . '_in', (array)$val['in']);
            }
            if (!empty($val['not_in'])) {
                $qb->andWhere("$alias.id NOT IN (:$key" . "_nin)")->setParameter($key . '_nin', (array)$val['not_in']);
            }
            return;
        }

        $qb->andWhere("$alias.id IN (:$key" . "_val)")->setParameter($key . '_val', (array)$val);
    }

    private function buildTitle(PmsEventoCalendario $evento, ?PmsReserva $reserva): string
    {
        $cliente = $reserva?->getNombreApellido();
        if (!$cliente) {
            return sprintf('Evento (%s)', $evento->getEstado()?->getNombre() ?? 'Sin Estado');
        }

        $channel = strtoupper((string)($evento?->getChannel()?->getId()[0] ?? 'X'));
        $pax = $evento->getCantidadAdultos() + $evento->getCantidadNinos();

        return sprintf('%s x%d | %s | %s', $channel, $pax, $cliente, (string)$evento->getPmsUnidad());
    }

    private function buildTooltip(PmsEventoCalendario $evento, ?PmsReserva $reserva): array
    {
        $lines = [
            (string) $evento->getPmsUnidad(),
            'Pax: ' . $reserva?->getNombreApellido(),
            'Estado: ' . ($evento->getEstado()?->getNombre() ?? '-'),
            'Pago: ' . ($evento->getEstadoPago()?->getNombre() ?? '-')
        ];

        if ($evento?->getReferenciaCanal()) {
            $lines[] = 'Ref: ' . $evento->getReferenciaCanal();
        }

        return $lines;
    }

    private function resolveColor(?PmsEventoEstado $estado, ?PmsEventoEstadoPago $estadoPago): ?string
    {
        if ($estadoPago?->isColorOverride()) return $estadoPago->getColor();
        return $estado?->getColor() ?? null;
    }

    /**
     * Cabeceras financieras de todas las reservas del rango, en UNA consulta.
     *
     * La relación va de PmsInformacionFinanciera hacia la reserva (JoinColumn
     * unique del lado de las finanzas), así que el evento no puede navegar hasta
     * ella: hay que buscarla. Y hay que hacerlo EN LOTE — un `findOneBy` por
     * evento serían ~200 consultas en una vista de mes, que es justo el tipo de
     * N+1 que hace inusable un calendario.
     *
     * **Gotcha, y de los caros**: esto NO puede ser un `IN (:reservas)` en DQL.
     * `reserva_id` es `BINARY(16)`, y pasar entidades u objetos `Uuid` por
     * `setParameter()` sin tipo de parámetro los serializa mal: la consulta no
     * falla, devuelve CERO filas, y el calendario se pinta sin cifras sin un
     * solo error en el log. Es la misma trampa que documenta
     * `TourTarjetaResolver::binarios()`. `findBy()` con las ENTIDADES sí acierta
     * porque el persister de Doctrine conoce el tipo de la columna destino del
     * mapeo: es la versión en lote del `findOneBy(['reserva' => …])` que ya usa
     * PmsReservaPaxProvider.
     *
     * El precio es no poder hacer eager load de la moneda; sale a una consulta
     * por moneda DISTINTA (el identity map deduplica), no por fila.
     *
     * @param array<int, mixed> $eventos
     *
     * @return array<string, PmsInformacionFinanciera> indexado por id de reserva
     */
    private function fetchFinanzas(array $eventos): array
    {
        $reservas = [];
        foreach ($eventos as $evento) {
            if ($evento instanceof PmsEventoCalendario && $evento->getReserva()?->getId() !== null) {
                $reservas[(string) $evento->getReserva()->getId()] = $evento->getReserva();
            }
        }

        if ([] === $reservas) {
            return [];
        }

        $em = $this->managerRegistry->getManagerForClass(PmsInformacionFinanciera::class);
        if (!$em instanceof EntityManagerInterface) {
            return [];
        }

        $filas = $em->getRepository(PmsInformacionFinanciera::class)
            ->findBy(['reserva' => array_values($reservas)]);

        $porReserva = [];
        foreach ($filas as $fila) {
            if ($fila instanceof PmsInformacionFinanciera && $fila->getReserva()?->getId() !== null) {
                $porReserva[(string) $fila->getReserva()->getId()] = $fila;
            }
        }

        return $porReserva;
    }

    /**
     * Identificación cruda para que el frontend arme su propia navegación,
     * sin acoplarse a rutas de EasyAdmin.
     *
     * Además de los ids, viajan los DATOS SUELTOS que pinta la barra y el
     * tooltip (canal, cliente, pax, estados, referencia, y las cifras de la
     * cabecera financiera). El `title` y el `tooltip` de arriba siguen
     * existiendo como texto plano —son el contrato del DTO y el respaldo si
     * algo falla—, pero el frontend prefiere esto: con el canal como
     * identificador puede pintar el icono de Airbnb o Booking en vez de la
     * inicial «A»/«B», y no tiene que re-parsear la cadena
     * «A x8 | Nombre | Casita» para saber qué es cada trozo.
     *
     * @return array<string,mixed>
     */
    private function buildContext(
        PmsEventoCalendario $evento,
        ?PmsReserva $reserva,
        ?PmsInformacionFinanciera $finanzas,
    ): array {
        // Sin cargos no hay cifras que pintar: un bloqueo o una reserva recién
        // creada mandan null y la barra simplemente no muestra la línea de dinero.
        $hayCifras = $finanzas !== null && (float) $finanzas->getTotalCargos() > 0;

        return [
            'context' => $reserva ? 'reserva' : 'bloqueo',
            'eventoId' => (string) $evento->getId(),
            'reservaId' => $reserva?->getId() ? (string) $reserva->getId() : null,
            'isOta' => $evento->isOta(),

            'canalId' => $evento->getChannel()?->getId(),
            'cliente' => $reserva?->getNombreApellido(),
            'unidad' => $evento->getPmsUnidad()?->getNombre(),
            'pax' => $evento->getCantidadAdultos() + $evento->getCantidadNinos(),
            'estado' => $evento->getEstado()?->getNombre(),
            'estadoPago' => $evento->getEstadoPago()?->getNombre(),
            'referenciaCanal' => $evento->getReferenciaCanal(),
            'noches' => $evento->getNoches(),
            // Horario extra: la barra los marca con un icono. La noche que bloquean
            // NO se pinta aquí — es un evento `extension` aparte, que este mismo
            // calendario filtra por YAML (ver PmsExtensionEstanciaService).
            'entradaTemprana' => $evento->isEntradaTemprana(),
            'salidaTardia' => $evento->isSalidaTardia(),

            // Cifras de la RESERVA, no de la estancia: una reserva de dos casitas
            // repite el mismo total en sus dos barras. Es intencionado — el saldo
            // se cobra una vez— pero conviene saberlo al leer el calendario.
            'simbolo' => $hayCifras ? $finanzas->getMoneda()?->getSimbolo() : null,
            'total' => $hayCifras ? $finanzas->getTotalCargos() : null,
            'saldo' => $hayCifras ? $finanzas->getSaldo() : null,
        ];
    }
}
