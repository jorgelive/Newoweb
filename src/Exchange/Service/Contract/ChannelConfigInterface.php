<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * Interface ChannelConfigInterface.
 * Define el contrato mínimo para las configuraciones de canales externos.
 * Compatible con la arquitectura de identificadores UUID (BINARY 16).
 */
interface ChannelConfigInterface
{
    /**
     * Identificador único: el UUID del `IdTrait`, como en las tres configuraciones que lo
     * implementan (`Beds24Config`, `MetaConfig`, `EmailConfig`). Decía `mixed`; ver el mismo
     * cambio en `ExchangeQueueItemInterface::getId()`.
     */
    public function getId(): ?Uuid;

    /**
     * Retorna el alias del proveedor que debe procesar esta configuración
     * Se utiliza para seleccionar el cliente.
     * Ejemplo: 'beds24', 'meta', 'booking'.
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

    /**
     * Nombre legible de la configuración, para mensajes de error y trazas.
     *
     * Está en las dos implementaciones (`Beds24Config` y `MetaConfig`) desde siempre; faltaba
     * en el contrato.
     */
    public function getNombre(): ?string;
}