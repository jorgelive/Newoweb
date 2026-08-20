<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;

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
