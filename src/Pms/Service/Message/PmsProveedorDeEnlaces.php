<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Los asuntos de alojamiento de un hilo.
 *
 * Es todo lo que el PMS aporta para estar en la mensajería: una clase que implementa el
 * contrato. Ni un `match` en el núcleo, ni una colección más en `MessageConversation`.
 *
 * ⚠️ **Mira también lo que aún no está en la base.** La colección que había antes servía para
 * eso —el comentario de `PmsSincronizadorDeEnlace` lo decía— y una consulta a secas no vería un
 * enlace creado en la misma petición: el sincronizador crearía un duplicado y el índice único
 * reventaría al hacer flush. Por eso se juntan las filas persistidas con las que la unidad de
 * trabajo tiene pendientes de insertar.
 */
final readonly class PmsProveedorDeEnlaces implements ProveedorDeEnlacesInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function getNegocio(): string
    {
        return 'pms';
    }

    /**
     * El `@return` es más estrecho que el del contrato a propósito: el núcleo sólo necesita la
     * interfaz, pero dentro del PMS se sabe el tipo concreto y sin declararlo aquí cada uso
     * tendría que castear.
     *
     * @return list<PmsConversacionEnlace>
     */
    public function paraConversacion(MessageConversation $conversacion): array
    {
        /** @var list<PmsConversacionEnlace> $persistidos */
        $persistidos = $this->em->getRepository(PmsConversacionEnlace::class)
            ->findBy(['conversacion' => $conversacion], ['createdAt' => 'ASC']);

        foreach ($this->pendientes($conversacion) as $pendiente) {
            $persistidos[] = $pendiente;
        }

        return $persistidos;
    }

    /**
     * El enlace TITULAR de una reserva, mirando en todos los hilos.
     *
     * Es el camino DURADERO: quien llega con la dirección del asunto —el `bookId` de Beds24—
     * sabe así en qué hilo se atiende, sin pasar por ninguna identidad de persona. Un `bookId`
     * es de la estancia, no de nadie: hay 46 repartidos en 38 hilos, y una misma persona
     * acumula siete.
     *
     * Mira también lo pendiente, por lo mismo que {@see self::paraConversacion()}: en el flujo
     * de alta, el enlace se crea y se consulta dentro de la misma unidad de trabajo.
     */
    public function titularDeAsunto(string $contextType, string $contextId): ?PmsConversacionEnlace
    {
        if ($contextType !== PmsConversacionEnlace::CONTEXT_TYPE) {
            return null;
        }

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof PmsConversacionEnlace
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

        /** @var ?PmsConversacionEnlace $enlace */
        $enlace = $this->em->getRepository(PmsConversacionEnlace::class)
            ->createQueryBuilder('e')
            ->join('e.reserva', 'r')
            ->where('r.id = :id')
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
    ): ?PmsConversacionEnlace {
        if ($contextType !== PmsConversacionEnlace::CONTEXT_TYPE) {
            return null;
        }

        foreach ($this->paraConversacion($conversacion) as $enlace) {
            if ($enlace->getContextId() === $contextId) {
                return $enlace;
            }
        }

        return null;
    }

    /**
     * El enlace de ESTA reserva en ESTE hilo, o `null`.
     *
     * Lo usa el sincronizador para no crear dos veces el mismo, que es la razón de que esto
     * tenga que ver lo pendiente además de lo guardado.
     */
    public function paraReserva(MessageConversation $conversacion, PmsReserva $reserva): ?PmsConversacionEnlace
    {
        foreach ($this->paraConversacion($conversacion) as $enlace) {
            if ($enlace->getReserva()?->getId()?->equals($reserva->getId()) === true) {
                return $enlace;
            }
        }

        return null;
    }

    /**
     * Los enlaces de este hilo que están creados pero todavía no en la base.
     *
     * @return list<PmsConversacionEnlace>
     */
    private function pendientes(MessageConversation $conversacion): array
    {
        $pendientes = [];

        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entidad) {
            if (!$entidad instanceof PmsConversacionEnlace) {
                continue;
            }

            if ($entidad->getConversacion() === $conversacion) {
                $pendientes[] = $entidad;
            }
        }

        return $pendientes;
    }
}
