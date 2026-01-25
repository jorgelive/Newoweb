<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use App\Exchange\Service\Common\ExchangeNetworkResult;
use App\Exchange\Service\Mapping\MappingResult;

interface ExchangeClientInterface
{
    public static function getClientAlias(): string;

    /**
     * @return ExchangeNetworkResult Contiene datos + auditoría (raw body, status code)
     */
    public function send(MappingResult $mapping): ExchangeNetworkResult;
}