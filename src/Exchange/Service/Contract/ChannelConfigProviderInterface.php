<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

/**
 * Interface ChannelConfigProviderInterface.
 * Lo firman las entidades (como Establecimiento o Agencia) que
 * poseen configuraciones de canales y pueden proveerlas dinámicamente.
 */
interface ChannelConfigProviderInterface
{
    /**
     * Retorna la configuración activa para el tipo de canal solicitado.
     * * @param string $channelType Ej: 'beds24', 'gupshup', 'smtp'
     */
    public function getChannelConfig(string $channelType): ?ChannelConfigInterface;
}