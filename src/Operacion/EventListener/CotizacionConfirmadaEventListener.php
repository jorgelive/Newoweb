<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOperacionEnum;
use App\Operacion\Service\BibliaSnapshotService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush)]
class CotizacionConfirmadaEventListener
{
    public function __construct(private readonly BibliaSnapshotService $snapshot)
    {
    }

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
        $metadata = $em->getClassMetadata(OperacionServicio::class);

        foreach ($this->snapshot->generarParaCotizacion($cotizacion) as $ops) {
            // Instruir manualmente a Doctrine para que inserte esta nueva entidad en el ciclo actual
            $uow->computeChangeSet($metadata, $ops);
        }
    }

    private function propagarEstadoOperacion(
        Cotizacion $cotizacion,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        EstadoOperacionEnum $estado,
    ): void {
        $cotservicios = $cotizacion->getCotservicios()->toArray();
        if ($cotservicios === []) {
            return;
        }

        // Se busca por la colección de cotservicios y no con un WHERE cs.cotizacion = :cot:
        // el id es un Uuid binario y la comparación en DQL no lo convierte, así que esa
        // consulta devolvía 0 filas en silencio y cancelar una cotización no propagaba nada.
        /** @var OperacionServicio[] $servicios */
        $servicios = $em->getRepository(OperacionServicio::class)
            ->findBy(['cotizacionServicio' => $cotservicios]);

        if (empty($servicios)) {
            return;
        }

        foreach ($servicios as $ops) {
            $ops->setEstadoOperacion($estado);

            // Recalcular los cambios para la entidad actualizada dentro del proceso de flush en curso
            $uow->computeChangeSet($em->getClassMetadata(OperacionServicio::class), $ops);
        }
    }
}
