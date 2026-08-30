<?php
declare(strict_types=1);

namespace App\Exchange\Service\Mapping;

use App\Exchange\Service\Common\HomogeneousBatch;

interface MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult;

    /**
     * @param array<array-key, mixed> $apiResponse La respuesta cruda del canal, ya decodificada.
     *
     * @return array<array-key, ItemResult> Indexado por queueItemId.
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array;
}