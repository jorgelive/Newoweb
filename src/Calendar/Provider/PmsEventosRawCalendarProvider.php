<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Pms\Entity\PmsEventoCalendario;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Proveedor de Calendario para PmsEventoCalendario (Raw).
 *
 * Carga eventos directamente de la entidad `PmsEventoCalendario` sin procesamiento
 * complejo de tarifas (pricing engine). Ideal para vistas administrativas,
 * auditoría de ocupación y gestión directa de bloqueos/reservas.
 *
 * Configuración esperada:
 *      pms_eventos_no_cancelados:
 *            provider: pms_eventos_raw
 *            filters:
 *                establecimientoId: null
 *                unidadIds: [ ]
 *                estado:
 *                    in: [ ]        # ej: [confirmada, bloqueado]
 *                    not_in: [cancelado]    # ej: [cancelada]
 *                estadoPago:
 *                    in: [ ]        # ej: [no-pagado, pago-parcial]
 *                    not_in: [ ]    # ej: [pago-total]
 *            event:
 *                url:
 *                    # Si hay reserva -> usa ReservaAdmin
 *                    reservaEdit:
 *                        role: ROLE_RESERVAS_EDITOR
 *                        route: dashboard_pms_reserva_edit
 *                        params:
 *                            tl: es
 *
 *                    reservaShow:
 *                        role: ROLE_USER
 *                        route: dashboard_pms_reserva_detail
 *                        params:
 *                            tl: es
 *
 *                    # Si NO hay reserva -> usa EventoCalendarioAdmin
 *                    eventoCalendarioEdit:
 *                        role: ROLE_RESERVAS_EDITOR
 *                        route: dashboard_pms_evento_calendario_edit
 *                        params:
 *                            tl: es
 *
 *                    eventoCalendarioShow:
 *                        role: ROLE_USER
 *                        route: dashboard_pms_evento_calendario_detail
 *                        params:
 *                            tl: es
 **/
final class PmsEventosRawCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
    ) {}

    /**
     * Verifica si este proveedor soporta la configuración solicitada.
     * Se basa en la clave 'provider' === 'pms_eventos_raw'.
     */
    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'pms_eventos_raw');
    }

    /**
     * Obtiene los eventos del calendario transformados a DTOs.
     *
     * Incluye lógica para:
     * 1. Filtrado por fechas y unidades.
     * 2. Formateo de títulos (Canal, Cliente, PAX).
     * 3. Cálculo de colores según Estado o EstadoPago.
     * 4. Generación de Tooltips detallados.
     * 5. Inyección de URLs de edición con soporte para navegación circular ('returnTo').
     *
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $eventos = $this->fetchEventos($from, $to, $config);
        $out = [];

        foreach ($eventos as $evento) {
            if (!$evento instanceof PmsEventoCalendario) {
                continue;
            }

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();
            if (!$inicio || !$fin) {
                continue;
            }

            $unidad = $evento->getPmsUnidad();
            $resourceId = $unidad?->getId();
            if (!is_int($resourceId)) {
                continue;
            }

            $reserva = $evento->getReserva();
            $estado = $evento->getEstado();
            $estadoPago = $evento->getEstadoPago();

            // =========================
            // TITULO
            // =========================
            $channelCode = (string) ($reserva?->getChannel()?->getCodigo() ?? '');
            $channelLetter = $channelCode !== '' ? strtoupper($channelCode[0]) : 'X';

            $ad = (int) ($evento->getCantidadAdultos() ?? 0);
            $ch = (int) ($evento->getCantidadNinos() ?? 0);

            $pax = (string) $ad;
            if ($ch > 0) {
                $pax .= '+' . $ch;
            }

            $prefix = sprintf('%s x%s', $channelLetter, $pax);

            $nombre = trim((string) ($reserva?->getNombreCliente() ?? ''));
            $apellido = trim((string) ($reserva?->getApellidoCliente() ?? ''));
            $cliente = trim($nombre . ' ' . $apellido);

            if ($cliente === '') {
                $estadoNombre = $estado?->getNombre() ?? 'Evento';
                $title = sprintf('Evento (%s)', $estadoNombre);
            } else {
                $unidadCodigo = $unidad ? (string) $unidad : '#?';
                $title = sprintf('%s | %s | %s', $prefix, $cliente, $unidadCodigo);
            }

            // =========================
            // COLOR
            // =========================
            $backgroundColor = null;
            if ($estado && $estado->isColorOverride()) {
                $backgroundColor = $estado->getColor();
            } elseif ($estadoPago && $estadoPago->getColor()) {
                $backgroundColor = $estadoPago->getColor();
            } elseif ($estado) {
                $backgroundColor = $estado->getColor();
            }

            // =========================
            // TOOLTIP
            // =========================
            $tooltip = [
                (string) $unidad,
                'Inicio: ' . $inicio->format('Y-m-d H:i'),
                'Fin: ' . $fin->format('Y-m-d H:i'),
                'Estado: ' . ($estado?->getNombre() ?? '-'),
                'Pago: ' . ($estadoPago?->getNombre() ?? '-'),
                sprintf('PAX: %d (%d+%d)', $ad + $ch, $ad, $ch),
            ];

            if ($reserva?->getReferenciaCanal()) {
                $tooltip[] = 'Ref: ' . $reserva->getReferenciaCanal();
            }

            // Generamos las URLs inyectando el returnTo
            [$urledit, $urlshow] = $this->buildUrls($evento, $reserva, $config);

            $out[] = new CalendarEventDto(
                id: $evento->getId() ?? spl_object_id($evento),
                title: $title,
                start: $inicio,
                end: $fin,
                resourceId: $resourceId,
                tooltip: $tooltip,
                urledit: $urledit,
                urlshow: $urlshow,
                backgroundColor: $backgroundColor,
            );
        }

        return $out;
    }

    /**
     * Obtiene los recursos (Unidades) asociados a los eventos del rango.
     * Útil para vistas de tipo 'resourceTimeline'.
     *
     * @return list<CalendarResourceDto>
     */
    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $eventos = $this->fetchEventos($from, $to, $config);
        $seen = [];
        $out = [];

        foreach ($eventos as $evento) {
            $unidad = $evento->getPmsUnidad();
            $id = $unidad?->getId();
            if (!is_int($id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = new CalendarResourceDto(id: $id, title: (string) $unidad);
        }

        return $out;
    }

    /**
     * Realiza la consulta a la base de datos aplicando filtros dinámicos.
     *
     * Filtros soportados:
     * - establecimientoId (int)
     * - unidadIds (array<int>)
     * - estado (string|array) -> filtra por código de PmsEventoEstado
     * - estadoPago (string|array) -> filtra por código de PmsEstadoPago
     *
     * @return list<PmsEventoCalendario>
     */
    private function fetchEventos(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $em = $this->managerRegistry->getManagerForClass(PmsEventoCalendario::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new HttpException(500, 'No hay EntityManager disponible para PmsEventoCalendario.');
        }

        $filters = (array) ($config['filters'] ?? []);

        $qb = $em->createQueryBuilder()
            ->select('e,u,est,r,ch,es,ep')
            ->from(PmsEventoCalendario::class, 'e')
            ->leftJoin('e.pmsUnidad', 'u')
            ->leftJoin('u.establecimiento', 'est')
            ->leftJoin('e.reserva', 'r')
            ->leftJoin('r.channel', 'ch')
            ->leftJoin('e.estado', 'es')
            ->leftJoin('e.estadoPago', 'ep')
            ->andWhere('e.inicio <= :to')
            ->andWhere('e.fin >= :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $establecimientoId = $filters['establecimientoId'] ?? null;
        if ($establecimientoId !== null && $establecimientoId !== '' && is_numeric($establecimientoId)) {
            $qb->andWhere('est.id = :establecimientoId')
                ->setParameter('establecimientoId', (int) $establecimientoId);
        }

        $unidadIds = $filters['unidadIds'] ?? null;
        if (is_array($unidadIds) && count($unidadIds) > 0) {
            $unidadIds = array_values(array_filter(array_map('intval', $unidadIds), static fn (int $v): bool => $v > 0));
            if (count($unidadIds) > 0) {
                $qb->andWhere('u.id IN (:unidadIds)')
                    ->setParameter('unidadIds', $unidadIds);
            }
        }

        $this->applyCodigoFilter($qb, 'es', 'estado', $filters);
        $this->applyCodigoFilter($qb, 'ep', 'estadoPago', $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * Aplica filtros por "código" (string) sobre una relación (Estado o EstadoPago).
     * Soporta valores únicos, arrays 'IN', y arrays con 'in'/'not_in'.
     */
    private function applyCodigoFilter(QueryBuilder $qb, string $alias, string $key, array $filters): void
    {
        if (!array_key_exists($key, $filters)) {
            return;
        }

        $val = $filters[$key];

        if (is_array($val) && (isset($val['in']) || isset($val['not_in']))) {
            if (!empty($val['in'])) {
                $qb->andWhere("$alias.codigo IN (:$key" . "_in)")
                    ->setParameter($key . '_in', $val['in']);
            }
            if (!empty($val['not_in'])) {
                $qb->andWhere("$alias.codigo NOT IN (:$key" . "_not_in)")
                    ->setParameter($key . '_not_in', $val['not_in']);
            }
            return;
        }

        if (is_array($val)) {
            $qb->andWhere("$alias.codigo IN (:$key" . "_arr)")
                ->setParameter($key . '_arr', $val);
            return;
        }

        if (is_string($val) && $val !== '') {
            $qb->andWhere("$alias.codigo = :$key" . "_eq")
                ->setParameter($key . '_eq', $val);
        }
    }

    /**
     * Construye las URLs de edición y visualización para el evento.
     *
     * 🔥 LÓGICA DE NAVEGACIÓN:
     * Recupera el parámetro 'runtime_returnTo' inyectado por el controlador
     * y lo agrega a la URL generada como 'returnTo'. Esto permite que, al
     * guardar en el Admin, el usuario regrese automáticamente al calendario.
     *
     * @return array{0: ?string, 1: ?string} [urlEdit, urlShow]
     */
    private function buildUrls(PmsEventoCalendario $evento, ?object $reserva, array $config): array
    {
        $cfg = $config['event']['url'] ?? null;
        if (!is_array($cfg)) {
            return [null, null];
        }

        // 1. CAPTURAMOS EL PASAPORTE (TOKEN BASE64)
        // Esta variable se inyecta dinámicamente en el Controller (FullcalendarLoadController)
        $runtimeReturnTo = $config['runtime_returnTo'] ?? null;

        $useReserva = false;
        $targetId = null;

        if ($reserva !== null && method_exists($reserva, 'getId')) {
            $rid = $reserva->getId();
            if (is_int($rid) && $rid > 0) {
                $useReserva = true;
                $targetId = $rid;
            }
        }

        if (!$useReserva) {
            $eid = $evento->getId();
            if (!is_int($eid) || $eid <= 0) {
                return [null, null];
            }
            $targetId = $eid;
        }

        $baseParams = [];
        if (isset($cfg['params']) && is_array($cfg['params'])) {
            $baseParams = $cfg['params'];
        }

        // Selección de claves según si editamos la Reserva o el Evento suelto
        if ($useReserva) {
            $keyEdit = array_key_exists('reservaEdit', $cfg) ? 'reservaEdit' : (array_key_exists('reseervaEdit', $cfg) ? 'reseervaEdit' : 'edit');
            $keyShow = array_key_exists('reservaShow', $cfg) ? 'reservaShow' : 'show';
        } else {
            $keyEdit = array_key_exists('eventoCalendarioEdit', $cfg) ? 'eventoCalendarioEdit' : 'edit';
            $keyShow = array_key_exists('eventoCalendarioShow', $cfg) ? 'eventoCalendarioShow' : 'show';
        }

        /**
         * Closure auxiliar para construir una URL específica.
         * Importamos $runtimeReturnTo para inyectarlo en los parámetros.
         */
        $build = function (string $blockKey) use ($cfg, $baseParams, $targetId, $runtimeReturnTo): ?string {
            if (!isset($cfg[$blockKey]) || !is_array($cfg[$blockKey])) {
                return null;
            }

            $route = $cfg[$blockKey]['route'] ?? null;
            if (!is_string($route) || $route === '') {
                return null;
            }

            $role = $cfg[$blockKey]['role'] ?? null;
            if (is_string($role) && $role !== '' && !$this->authorizationChecker->isGranted($role)) {
                return null;
            }

            $params = [
                'id'       => $targetId, // Parámetro para Legacy Admin / Oweb
                'entityId' => $targetId, // Parámetro estándar para EasyAdmin
            ];

            if (!empty($baseParams)) {
                $params = array_merge($params, $baseParams);
            }

            if (isset($cfg[$blockKey]['params']) && is_array($cfg[$blockKey]['params'])) {
                $params = array_merge($params, $cfg[$blockKey]['params']);
            }

            if (!array_key_exists('tl', $params)) {
                $params['tl'] = 'es';
            }

            // 🔥 2. INYECCIÓN DEL TOKEN DE NAVEGACIÓN
            // Si existe el token, lo agregamos como 'returnTo'.
            // El Listener y los Controladores del Panel usarán esto para volver.
            if (!empty($runtimeReturnTo)) {
                $params['returnTo'] = $runtimeReturnTo;
            }

            return $this->router->generate($route, $params);
        };

        return [$build($keyEdit), $build($keyShow)];
    }
}