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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush)]
class CotizacionConfirmadaEventListener
{
    /**
     * Intercepta el proceso de sincronización con la base de datos para evaluar
     * cambios de estado en Cotizacion.
     *
     * Utilizar onFlush es la estrategia recomendada por Doctrine para persistir o modificar
     * otras entidades (OperacionServicio) en reacción a un cambio, garantizando que todo
     * ocurra en la misma transacción sin causar bucles infinitos por flushes anidados.
     *
     * @param OnFlushEventArgs $args Argumentos proporcionados por Doctrine durante el flush.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Iterar únicamente sobre las entidades que tienen actualizaciones programadas
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Cotizacion) {
                continue;
            }

            // Tours de catálogo: producto de exhibición con fechas nominales,
            // sin expediente real — nunca deben generar operación en La Biblia.
            if ($entity->getCatalogo() !== null) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);

            // Validar si el campo 'estado' fue uno de los que cambió
            if (!isset($changeSet['estado'])) {
                continue;
            }

            // $changeSet['estado'][0] es el valor viejo, [1] es el nuevo valor
            $nuevoEstado = $changeSet['estado'][1];

            // Si Doctrine devuelve un string en lugar del Enum debido a la configuración de mapeo, lo parseamos
            if (is_string($nuevoEstado)) {
                $nuevoEstado = CotizacionEstadoEnum::tryFrom($nuevoEstado);
            }

            match ($nuevoEstado) {
                CotizacionEstadoEnum::CONFIRMADO => $this->generarSnapshotBiblia($entity, $em, $uow),
                CotizacionEstadoEnum::CANCELADO  => $this->propagarEstadoOperacion($entity, $em, $uow, EstadoOperacionEnum::CANCELADO),
                CotizacionEstadoEnum::PENDIENTE,
                CotizacionEstadoEnum::ENVIADO,
                CotizacionEstadoEnum::ARCHIVADO  => $this->propagarEstadoOperacion($entity, $em, $uow, EstadoOperacionEnum::PENDIENTE),
                default                          => null,
            };
        }
    }

    private function generarSnapshotBiblia(Cotizacion $cotizacion, EntityManagerInterface $em, UnitOfWork $uow): void
    {
        $file          = $cotizacion->getFile();
        $cantidadPax   = $cotizacion->getNumPax();
        $osRepo        = $em->getRepository(OperacionServicio::class);

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

                // Instruir manualmente a Doctrine para que inserte esta nueva entidad en el ciclo actual
                $uow->computeChangeSet($em->getClassMetadata(OperacionServicio::class), $ops);
            }
        }
    }

    private function propagarEstadoOperacion(
        Cotizacion $cotizacion,
        EntityManagerInterface $em,
        UnitOfWork $uow,
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

            // Recalcular los cambios para la entidad actualizada dentro del proceso de flush en curso
            $uow->computeChangeSet($em->getClassMetadata(OperacionServicio::class), $ops);
        }
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