<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

/**
 * Interface ChannelConfigInterface.
 * Define el contrato mínimo para las configuraciones de canales externos.
 * Compatible con la arquitectura de identificadores UUID (BINARY 16).
 */
interface ChannelConfigInterface
{
    /**
     * Retorna el identificador único.
     * Cambiado a 'mixed' o 'string' para soportar UUIDs binarios/hex.
     */
    public function getId(): mixed;

    /**
     * Retorna el alias del proveedor que debe procesar esta configuración.
     * Ejemplo: 'beds24', 'airbnb', 'booking'.
     */
    public function getProviderName(): string;

    /**
     * Retorna la URL base de la API para este proveedor específico.
     * Ejemplo: 'https://api.beds24.com/v2'.
     * Centraliza la configuración evitando dependencias de variables de entorno globales.
     */
    public function getBaseUrl(): string;

    /**
     * Indica si la configuración del canal está operativa.
     */
    public function isActivo(): ?bool;
}