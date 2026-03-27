<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Message\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;

readonly class MessageJsonMerger
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Realiza un UPDATE atómico a nivel de base de datos usando JSON_MERGE_PATCH.
     * Esto ignora por completo la memoria de PHP y las transacciones, garantizando
     * que procesos concurrentes (Webhooks y Workers) nunca sobreescriban sus datos mutuamente.
     */
    public function merge(
        Message $message,
        string $metaKey,
        array $metaData,
        ?string $externalIdKey = null,
        ?string $externalIdValue = null
    ): void {
        $conn = $this->em->getConnection();
        $id = $message->getId()->toBinary();

        // Preparamos payload de metadata (JSON_MERGE_PATCH hace un deep merge automático)
        $metaPayload = json_encode([$metaKey => $metaData], JSON_THROW_ON_ERROR);

        $sql = "UPDATE msg_message 
                SET metadata = JSON_MERGE_PATCH(COALESCE(metadata, '{}'), :meta)";

        $params = [
            'meta' => $metaPayload,
            'id'   => $id
        ];

        // Si hay external ID, lo mergeamos también atómicamente
        if ($externalIdKey && $externalIdValue) {
            $extPayload = json_encode([$externalIdKey => $externalIdValue], JSON_THROW_ON_ERROR);
            $sql .= ", external_ids = JSON_MERGE_PATCH(COALESCE(external_ids, '{}'), :ext)";
            $params['ext'] = $extPayload;
        }

        $sql .= " WHERE id = :id";

        // Disparamos la actualización atómica a la BD
        $conn->executeStatement($sql, $params);

        // Sincronizamos la entidad en PHP por si el proceso actual sigue usándola
        $this->em->refresh($message);
    }
}