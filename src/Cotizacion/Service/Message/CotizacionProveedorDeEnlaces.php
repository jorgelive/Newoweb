<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Los expedientes de viaje de un hilo.
 *
 * Es **todo** lo que Travel necesita para estar en la mensajería: esta clase y la entidad del
 * enlace. Ni un `match` en el núcleo, ni una colección más en `MessageConversation`, ni tocar
 * el registro — se autolocaliza por `#[AutoconfigureTag]`.
 *
 * Con esto, un cliente de hotel que además compra tours tiene los dos asuntos en el mismo hilo
 * y quien atiende ve el historial completo.
 */
final readonly class CotizacionProveedorDeEnlaces implements ProveedorDeEnlacesInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function getNegocio(): string
    {
        return 'turistico';
    }

    /**
     * @return list<CotizacionConversacionEnlace>
     */
    public function paraConversacion(MessageConversation $conversacion): array
    {
        /** @var list<CotizacionConversacionEnlace> $persistidos */
        $persistidos = $this->em->getRepository(CotizacionConversacionEnlace::class)
            ->findBy(['conversacion' => $conversacion], ['createdAt' => 'ASC']);

        foreach ($this->pendientes($conversacion) as $pendiente) {
            $persistidos[] = $pendiente;
        }

        return $persistidos;
    }

    /** El enlace de ESTE expediente en ESTE hilo, o `null`. */
    public function paraFile(MessageConversation $conversacion, CotizacionFile $file): ?CotizacionConversacionEnlace
    {
        foreach ($this->paraConversacion($conversacion) as $enlace) {
            if ($enlace->getFile()?->getId()?->equals($file->getId()) === true) {
                return $enlace;
            }
        }

        return null;
    }

    /**
     * Los de este hilo creados pero todavía no en la base.
     *
     * Sin esto, crear dos veces en la misma petición pasaría la comprobación y reventaría el
     * índice único al hacer flush — el mismo motivo que en el proveedor del PMS.
     *
     * @return list<CotizacionConversacionEnlace>
     */
    private function pendientes(MessageConversation $conversacion): array
    {
        $pendientes = [];

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof CotizacionConversacionEnlace && $entidad->getConversacion() === $conversacion) {
                $pendientes[] = $entidad;
            }
        }

        return $pendientes;
    }

    /**
     * El enlace TITULAR de un expediente, mirando en todos los hilos. Ver el contrato.
     */
    public function titularDeAsunto(string $contextType, string $contextId): ?CotizacionConversacionEnlace
    {
        if ($contextType !== CotizacionConversacionEnlace::CONTEXT_TYPE) {
            return null;
        }

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof CotizacionConversacionEnlace
                && $entidad->esTitular()
                && $entidad->getContextId() === $contextId) {
                return $entidad;
            }
        }

        // ⚠️ El id va como Uuid CON su tipo. Las columnas son `binary(16)`: pasar la cadena
        // con guiones compara texto contra binario y la consulta devuelve vacío **sin error**,
        // que es la trampa que ya costó una tarde con los filtros de Travel (docs/Travel.md §11).
        if (!Uuid::isValid($contextId)) {
            return null;
        }

        /** @var ?CotizacionConversacionEnlace $enlace */
        $enlace = $this->em->getRepository(CotizacionConversacionEnlace::class)
            ->createQueryBuilder('e')
            ->join('e.file', 'f')
            ->where('f.id = :id')
            ->andWhere('e.esTitular = true')
            ->setParameter('id', Uuid::fromString($contextId), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $enlace;
    }

    public function enlaceDeAsunto(
        MessageConversation $conversacion,
        string $contextType,
        string $contextId
    ): ?CotizacionConversacionEnlace {
        if ($contextType !== CotizacionConversacionEnlace::CONTEXT_TYPE) {
            return null;
        }

        foreach ($this->paraConversacion($conversacion) as $enlace) {
            if ($enlace->getContextId() === $contextId) {
                return $enlace;
            }
        }

        return null;
    }
}
