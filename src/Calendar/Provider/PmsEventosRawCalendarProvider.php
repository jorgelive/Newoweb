<?php

declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Config\ConfiguracionCalendario;
use App\Calendar\Config\FiltroDeIds;
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

    public function supports(ConfiguracionCalendario $config): bool
    {
        return $config->provider === 'pms_eventos_raw';
    }

    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
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

    public function getResources(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
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
        return $this->resourceCatalog->merge($out, $config->recursos, PmsUnidad::class);
    }

    /**
     * @return list<\App\Pms\Entity\PmsEventoCalendario>
     */
    private function fetchEventos(DateTimeInterface $from, DateTimeInterface $to, ConfiguracionCalendario $config): array
    {
        $em = $this->managerRegistry->getManagerForClass(PmsEventoCalendario::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new HttpException(500, 'EntityManager no disponible.');
        }

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

        $this->applyIdFilter($qb, 'es', 'estado', $config->filtros->estado);
        $this->applyIdFilter($qb, 'ep', 'estadoPago', $config->filtros->estadoPago);

        /** @var list<\App\Pms\Entity\PmsEventoCalendario> $resultado */
        $resultado = $qb->getQuery()->getResult();

        return $resultado;
    }

    /**
     * Las dos formas del YAML (`{in, not_in}` y la lista plana) ya llegan unificadas en
     * {@see FiltroDeIds}: la plana es un `in`.
     */
    private function applyIdFilter(QueryBuilder $qb, string $alias, string $key, FiltroDeIds $filtro): void
    {
        if ($filtro->incluir !== []) {
            $qb->andWhere("$alias.id IN (:$key" . "_in)")->setParameter($key . '_in', $filtro->incluir);
        }
        if ($filtro->excluir !== []) {
            $qb->andWhere("$alias.id NOT IN (:$key" . "_nin)")->setParameter($key . '_nin', $filtro->excluir);
        }
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
     * @return array{0: string|null, 1: string|null} La URL de editar y la de ver, en ese orden.
     */
    private function buildUrls(PmsEventoCalendario $evento, ?PmsReserva $reserva, ConfiguracionCalendario $config): array
    {
        $enlaces = $config->evento->enlaces;
        if ($enlaces === null) return [null, null];

        $targetId = ($reserva && $reserva->getId()) ? $reserva->getId() : $evento->getId();
        if (!$targetId) return [null, null];

        $retorno = $config->retorno;

        $build = function(string $type) use ($enlaces, $targetId, $retorno, $reserva): ?string {
            $context = $reserva ? 'reserva' : 'eventoCalendario';
            $block = $enlaces->enlace($context . ucfirst($type)) ?? $enlaces->enlace($type);

            if ($block === null || $block->nombreRuta === null) return null;
            // Aquí el rol es OPCIONAL: sin `role`, el enlace sale para todos. Pero uno declarado
            // que no se pueda leer deniega, como denegaba `isGranted()` al recibirlo crudo.
            if ($block->rolDeclarado && ($block->rol === null || !$this->authorizationChecker->isGranted($block->rol))) return null;

            $params = array_merge(['id' => $targetId, 'entityId' => $targetId, 'tl' => 'es'], $block->parametros);
            if (!empty($retorno)) $params['returnTo'] = $retorno;

            return $this->router->generate($block->nombreRuta, $params);
        };

        return [$build('edit'), $build('show')];
    }
}