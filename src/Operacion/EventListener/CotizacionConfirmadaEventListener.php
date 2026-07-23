<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Travel\Enum\TarifaRolEnum;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postUpdate)]
class CotizacionConfirmadaEventListener
{
    /** @var array<string, CotizacionEstadoEnum> */
    private array $pendingEstado = [];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Cotizacion) {
            return;
        }

        if (!$args->hasChangedField('estado')) {
            return;
        }

        $nuevo = $args->getNewValue('estado');

        $relevantes = [
            CotizacionEstadoEnum::CONFIRMADO,
            CotizacionEstadoEnum::CANCELADO,
            CotizacionEstadoEnum::PENDIENTE,
            CotizacionEstadoEnum::ENVIADO,
            CotizacionEstadoEnum::ARCHIVADO,
        ];

        if (in_array($nuevo, $relevantes, true)) {
            $this->pendingEstado[$entity->getId()->toRfc4122()] = $nuevo;
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Cotizacion) {
            return;
        }

        $id = $entity->getId()->toRfc4122();
        $nuevoEstado = $this->pendingEstado[$id] ?? null;
        if ($nuevoEstado === null) {
            return;
        }

        unset($this->pendingEstado[$id]);
        $em = $args->getObjectManager();

        match ($nuevoEstado) {
            CotizacionEstadoEnum::CONFIRMADO => $this->generarSnapshotBiblia($entity, $em),
            CotizacionEstadoEnum::CANCELADO  => $this->propagarEstadoOperacion($entity, $em, EstadoOperacionEnum::CANCELADO),
            CotizacionEstadoEnum::PENDIENTE,
            CotizacionEstadoEnum::ENVIADO,
            CotizacionEstadoEnum::ARCHIVADO  => $this->propagarEstadoOperacion($entity, $em, EstadoOperacionEnum::PENDIENTE),
            default                          => null,
        };
    }

    private function generarSnapshotBiblia(Cotizacion $cotizacion, object $em): void
    {
        $file          = $cotizacion->getFile();
        $cantidadPax   = $cotizacion->getNumPax();
        $osRepo        = $em->getRepository(OperacionServicio::class);
        $nuevos        = [];

        foreach ($cotizacion->getCotservicios() as $cotservicio) {
            foreach ($cotservicio->getCotcomponentes() as $cotcomponente) {
                // Idempotencia: saltar si ya existe un OperacionServicio para este componente
                if ($osRepo->findOneBy(['cotizacionComponente' => $cotcomponente]) !== null) {
                    continue;
                }

                // ── Tarifa primaria ──────────────────────────────────────────
                $tarifa = $this->resolverTarifaPrimaria($cotcomponente);
                if ($tarifa === null || $tarifa->getMoneda() === null) {
                    continue; // Sin tarifa o sin moneda asignada: no se puede colocar
                }

                // ── Fecha de servicio ────────────────────────────────────────
                $fechaServicio = $this->resolverFechaServicio($cotcomponente, $cotservicio);
                if ($fechaServicio === null) {
                    continue; // Sin fecha: no se puede ubicar en La Biblia
                }

                // ── Hora de recojo ───────────────────────────────────────────
                $horaRecojoReal = $this->resolverHoraRecojo($cotcomponente);

                // ── Descripción operativa ────────────────────────────────────
                $descripcion = $this->resolverDescripcion($tarifa, $cotcomponente);

                // ── Moneda ───────────────────────────────────────────────────
                $moneda = $tarifa->getMoneda();

                $ops = new OperacionServicio();
                $ops->setFile($file);
                $ops->setCotizacionServicio($cotservicio);
                $ops->setCotizacionComponente($cotcomponente);
                $ops->setCotizacionTarifa($tarifa);
                $ops->setFechaServicio($fechaServicio);
                $ops->setHoraRecojoReal($horaRecojoReal);
                $ops->setProveedorMaestroId($tarifa->getProveedorMaestroId());
                $ops->setProveedorNombreManual($tarifa->getProveedorNombreSnapshot());
                $ops->setDescripcionServicio($descripcion);
                $ops->setCantidadPax($cantidadPax);
                $ops->setCostoCotizado($tarifa->getMontoCosto());
                $ops->setMonedaCotizada($moneda);
                $ops->setMontoVenta('0.00');
                $ops->setCostoRealOperativo('0.00');
                $ops->setMonedaReal($moneda);

                $em->persist($ops);
                $nuevos[] = $ops;
            }
        }

        if (!empty($nuevos)) {
            $em->flush();
        }
    }

    private function propagarEstadoOperacion(
        Cotizacion $cotizacion,
        object $em,
        EstadoOperacionEnum $estado,
    ): void {
        /** @var OperacionServicio[] $servicios */
        $servicios = $em->createQueryBuilder()
            ->select('os')
            ->from(OperacionServicio::class, 'os')
            ->join('os.cotizacionServicio', 'cs')
            ->where('cs.cotizacion = :cotizacion')
            ->setParameter('cotizacion', $cotizacion)
            ->getQuery()
            ->getResult();

        if (empty($servicios)) {
            return;
        }

        foreach ($servicios as $ops) {
            $ops->setEstadoOperacion($estado);
        }

        $em->flush();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolverTarifaPrimaria(CotizacionCotcomponente $componente): ?CotizacionCottarifa
    {
        // Solo estandar y operativo entran a La Biblia; alternativa es para ventas opcionales
        $tarifas = array_filter(
            $componente->getCottarifas()->toArray(),
            static fn (CotizacionCottarifa $t): bool =>
                TarifaRolEnum::tryFrom($t->getRolSnapshot() ?? '') !== TarifaRolEnum::ALTERNATIVA
        );

        if (empty($tarifas)) {
            return null;
        }

        usort($tarifas, static fn (CotizacionCottarifa $a, CotizacionCottarifa $b): int =>
            ($a->getGrupoTarifa() ?? PHP_INT_MAX) <=> ($b->getGrupoTarifa() ?? PHP_INT_MAX)
        );

        return array_values($tarifas)[0];
    }

    private function resolverFechaServicio(
        CotizacionCotcomponente $componente,
        \App\Cotizacion\Entity\CotizacionCotservicio $cotservicio,
    ): ?DateTimeImmutable {
        $inicio = $componente->getFechaHoraInicio();
        if ($inicio !== null) {
            // Normalizar a solo fecha (medianoche UTC) para el campo date_immutable
            return new DateTimeImmutable($inicio->format('Y-m-d'));
        }

        // Fallback: fecha base del servicio padre
        return $cotservicio->getFechaInicioAbsoluta();
    }

    private function resolverHoraRecojo(CotizacionCotcomponente $componente): ?string
    {
        $inicio = $componente->getFechaHoraInicio();
        if ($inicio === null || $componente->isSinHorario()) {
            return null;
        }

        return $inicio->format('H:i');
    }

    private function resolverDescripcion(
        CotizacionCottarifa $tarifa,
        CotizacionCotcomponente $componente,
    ): string {
        // Prioridad 1: nombre interno de la tarifa (campo operativo)
        $descripcion = trim($tarifa->getNombreInternoSnapshot() ?? '');
        if ($descripcion !== '') {
            return $descripcion;
        }

        // Prioridad 2: nombre del componente en español (snapshot i18n)
        foreach ($componente->getNombreSnapshot() as $item) {
            if (($item['language'] ?? '') === 'es') {
                $texto = trim(strip_tags($item['content'] ?? ''));
                if ($texto !== '') {
                    return $texto;
                }
            }
        }

        return 'Servicio sin nombre';
    }
}
