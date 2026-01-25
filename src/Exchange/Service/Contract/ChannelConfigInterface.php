<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

interface ChannelConfigInterface
{
    public function getId(): ?int;

    /**
     * Retorna el alias del cliente que debe procesar esta config (ej: 'beds24').
     */
    public function getProviderName(): string;

    /**
     * Retorna la URL base de la API (ej: 'https://api.beds24.com/v2').
     * Permite eliminar variables de entorno hardcodeadas.
     */
    public function getBaseUrl(): string;

    public function isActivo(): ?bool;
}