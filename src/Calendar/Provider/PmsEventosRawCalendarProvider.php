<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use App\Calendar\Service\CalendarResourceCatalog;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
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
        private readonly CalendarResourceCatalog $resourceCatalog,
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
            if (!$evento instanceof PmsEventoCalendario) continue;

            $inicio = $evento->getInicio();
            $fin = $evento->getFin();
            $unidad = $evento->getPmsUnidad();

            if (!$inicio || !$fin || !$unidad) continue;

            $reserva = $evento->getReserva();
            $estado = $evento->getEstado();
            $estadoPago = $evento->getEstadoPago();

            [$urledit, $urlshow] = $this->buildUrls($evento, $reserva, $config);

            $out[] = new CalendarEventDto(
                id: $evento->getId() ?? spl_object_id($evento),
                title: $this->buildTitle($evento, $reserva),
                start: $inicio,
                end: $fin,
                resourceId: $unidad->getId(),
                backgroundColor: $this->resolveColor($estado, $estadoPago),
                urledit: $urledit,
                urlshow: $urlshow,
                tooltip: $this->buildTooltip($evento, $reserva)
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

    /**
     * @param array<string, mixed> $config La configuración del calendario, tal como llega del YAML.
     * @return list<\App\Pms\Entity\PmsEventoCalendario>
     */
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
            // Ver PmsEventosSpaCalendarProvider: las extensiones no son estancias.
            ->andWhere('e.eventoOrigen IS NULL')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $this->applyIdFilter($qb, 'es', 'estado', $filters);
        $this->applyIdFilter($qb, 'ep', 'estadoPago', $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     */
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

        $channel = strtoupper((string)($evento->getChannel()?->getId()[0] ?? 'X'));
        $pax = $evento->getCantidadAdultos() + $evento->getCantidadNinos();

        return sprintf('%s x%d | %s | %s', $channel, $pax, $cliente, (string)$evento->getPmsUnidad());
    }

    /**
     * @return list<string> Las líneas del tooltip, ya redactadas.
     */
    private function buildTooltip(PmsEventoCalendario $evento, ?PmsReserva $reserva): array
    {
        $lines = [
            (string) $evento->getPmsUnidad(),
            'Pax: ' . $reserva?->getNombreApellido(),
            'Estado: ' . ($evento->getEstado()?->getNombre() ?? '-'),
            'Pago: ' . ($evento->getEstadoPago()?->getNombre() ?? '-')
        ];

        if ($evento->getReferenciaCanal()) {
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
     * @param array<string, mixed> $config La configuración del calendario, tal como llega del YAML.
     * @return array{0: string|null, 1: string|null} La URL de ver y la de editar.
     */
    private function buildUrls(PmsEventoCalendario $evento, ?PmsReserva $reserva, array $config): array
    {
        $cfg = $config['event']['url'] ?? null;
        if (!is_array($cfg)) return [null, null];

        $targetId = ($reserva && $reserva->getId()) ? $reserva->getId() : $evento->getId();
        if (!$targetId) return [null, null];

        $build = function(string $type) use ($cfg, $targetId, $config, $reserva): ?string {
            $context = $reserva ? 'reserva' : 'eventoCalendario';
            $block = $cfg[$context . ucfirst($type)] ?? $cfg[$type] ?? null;

            if (!is_array($block) || !isset($block['route'])) return null;
            if (isset($block['role']) && !$this->authorizationChecker->isGranted($block['role'])) return null;

            $params = array_merge(['id' => $targetId, 'entityId' => $targetId, 'tl' => 'es'], $block['params'] ?? []);
            if (!empty($config['runtime_returnTo'])) $params['returnTo'] = $config['runtime_returnTo'];

            return $this->router->generate($block['route'], $params);
        };

        return [$build('edit'), $build('show')];
    }
}