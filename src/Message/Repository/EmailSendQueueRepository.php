<?php

declare(strict_types=1);

namespace App\Message\Repository;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Enum\ConnectivityProvider;
use App\Exchange\Repository\AbstractExchangeRepository;
use Doctrine\DBAL\ArrayParameterType;
use App\Message\Entity\EmailSendQueue;
use Doctrine\Persistence\ManagerRegistry;

/**
 * La cola de correo.
 *
 * Hereda de `AbstractExchangeRepository` el `claimRunnable()`/`claimSpecificItems()` con bloqueo
 * por worker y TTL — la parte cara del envío fiable, y la razón de que el correo entre por el
 * motor de Exchange en vez de por una llamada suelta al mailer.
 *
 * @extends AbstractExchangeRepository<EmailSendQueue>
 */
final class EmailSendQueueRepository extends AbstractExchangeRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailSendQueue::class);
    }

    /** El bloqueo por worker se hace en SQL nativo, y necesita el nombre real de la tabla. */
    protected function getTableName(): string
    {
        return 'msg_email_send_queue';
    }

    /**
     * Rehidrata los elementos reclamados, con el mensaje y la configuración en el mismo viaje.
     *
     * ⚠️ **Sin `innerJoin` al endpoint**, al contrario que los demás canales: el correo no tiene
     * endpoint propio —su destino es un buzón— y un `innerJoin` habría devuelto cero filas
     * silenciosamente, dejando la cola llena y el worker sin nada que hacer.
     *
     * @param list<string> $ids
     * @return list<EmailSendQueue>
     */
    protected function hydrateItems(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<EmailSendQueue> $items */
        $items = $this->createQueryBuilder('q')
            ->addSelect('msg', 'cfg')
            ->innerJoin('q.message', 'msg')
            ->innerJoin('q.config', 'cfg')
            ->andWhere('q.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * El endpoint marcador del correo.
     *
     * `HomogeneousBatch` exige un endpoint porque los demás canales hablan con una API. El
     * destino de un correo es un buzón, así que éste no apunta a ninguna ruta: existe para que
     * el lote se pueda armar, y el cliente lo ignora.
     */
    public function endpointDeCorreo(): ?ExchangeEndpoint
    {
        return $this->getEntityManager()->getRepository(ExchangeEndpoint::class)
            ->findOneBy(['provider' => ConnectivityProvider::EMAIL, 'accion' => 'email_send']);
    }
}
