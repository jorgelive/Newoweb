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

final class PmsEventosRawCalendarProvider implements CalendarProviderInterface
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $router,
    ) {}

    public function supports(array $config): bool
    {
        return (($config['provider'] ?? null) === 'pms_eventos_raw');
    }

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

    private function fetchEventos(DateTimeInterface $from, DateTimeInterface $to, array $config): array
    {
        $em = $this->managerRegistry->getManagerForClass(PmsEventoCalendario::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new HttpException(500, 'No hay EntityManager');
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

        // filters.establecimientoId (int|null)
        $establecimientoId = $filters['establecimientoId'] ?? null;
        if ($establecimientoId !== null && $establecimientoId !== '' && is_numeric($establecimientoId)) {
            $qb->andWhere('est.id = :establecimientoId')
                ->setParameter('establecimientoId', (int) $establecimientoId);
        }

        // filters.unidadIds (list<int>)
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

    private function buildUrls(PmsEventoCalendario $evento, ?object $reserva, array $config): array
    {
        $cfg = $config['event']['url'] ?? null;
        if (!is_array($cfg)) {
            return [null, null];
        }

        // Decide target admin depending on whether there is a reserva
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

        // Base params (optional)
        $baseParams = [];
        if (isset($cfg['params']) && is_array($cfg['params'])) {
            $baseParams = $cfg['params'];
        }

        // Key mapping (support legacy keys too)
        // NOTE: user yaml sometimes had a typo `reseervaEdit`, we support it.
        if ($useReserva) {
            $keyEdit = array_key_exists('reservaEdit', $cfg) ? 'reservaEdit' : (array_key_exists('reseervaEdit', $cfg) ? 'reseervaEdit' : 'edit');
            $keyShow = array_key_exists('reservaShow', $cfg) ? 'reservaShow' : 'show';
        } else {
            $keyEdit = array_key_exists('eventoCalendarioEdit', $cfg) ? 'eventoCalendarioEdit' : 'edit';
            $keyShow = array_key_exists('eventoCalendarioShow', $cfg) ? 'eventoCalendarioShow' : 'show';
        }

        $edit = null;
        $show = null;

        // Helper to build a single url from a block
        $build = function (string $blockKey) use ($cfg, $baseParams, $targetId): ?string {
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

            $params = ['id' => $targetId];

            if (!empty($baseParams)) {
                $params = array_merge($params, $baseParams);
            }

            if (isset($cfg[$blockKey]['params']) && is_array($cfg[$blockKey]['params'])) {
                $params = array_merge($params, $cfg[$blockKey]['params']);
            }

            // default tl=es if not provided
            if (!array_key_exists('tl', $params)) {
                $params['tl'] = 'es';
            }

            return $this->router->generate($route, $params);
        };

        $edit = $build($keyEdit);
        $show = $build($keyShow);

        return [$edit, $show];
    }
}