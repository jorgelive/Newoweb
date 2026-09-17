<?php

declare(strict_types=1);

namespace App\Exchange\Service\Engine;

use App\Exchange\Service\Common\HomogeneousBatch;
use App\Exchange\Service\Contract\VetoableQueueItemInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aparta de un lote recién reclamado los ítems que ya no deben ejecutarse.
 *
 * Es la ÚLTIMA puerta antes de la red, y por eso vale más que cualquier cascada: da igual cómo se
 * desincronizaron la decisión y la cola —una cascada fallida, un comando, un `UPDATE` a mano—. Ver
 * {@see VetoableQueueItemInterface} para qué colas se apuntan y por qué no es una regla general.
 *
 * Corre DESPUÉS de reclamar y no dentro del SQL de `claimRunnable()`: ese SQL es común a todas las
 * colas y no sabe qué es un mensaje. Y corre ANTES de la transacción del envío, con su propio
 * flush, para que un vetado quede cancelado aunque el envío del resto reviente.
 */
final readonly class FiltroDeVetos
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /** El lote sin los vetados, o `null` si no queda nada que ejecutar. */
    public function apartar(HomogeneousBatch $lote, string $tarea): ?HomogeneousBatch
    {
        $quedan = [];
        $vetados = 0;
        $ahora = new DateTimeImmutable();

        foreach ($lote->getItems() as $item) {
            $motivo = $item instanceof VetoableQueueItemInterface ? $item->motivoParaNoEjecutar() : null;

            if (!$item instanceof VetoableQueueItemInterface || $motivo === null) {
                $quedan[] = $item;
                continue;
            }

            $item->marcarVetado($motivo, $ahora);
            ++$vetados;

            $this->logger->warning('Ítem de cola vetado antes de ejecutarse.', [
                'tarea' => $tarea,
                'item' => (string) $item->getId(),
                'motivo' => $motivo,
            ]);
        }

        if ($vetados === 0) {
            return $lote;
        }

        $this->em->flush();

        return $quedan === [] ? null : new HomogeneousBatch($lote->getConfig(), $lote->getEndpoint(), $quedan);
    }
}
