<?php
declare(strict_types=1);

namespace App\Exchange\Service\Mapping;

use App\Exchange\Service\Common\HomogeneousBatch;

interface MappingStrategyInterface
{
    public function map(HomogeneousBatch $batch): MappingResult;

    /**
     * @return ItemResult[] Array indexado por queueItemId
     */
    public function parseResponse(array $apiResponse, MappingResult $mapping): array;
}