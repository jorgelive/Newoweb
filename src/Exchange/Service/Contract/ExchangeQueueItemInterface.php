<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\Beds24Endpoint;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Interface ExchangeQueueItemInterface.
 * Define el ciclo de vida de un ítem en la cola de sincronización.
 * Compatible con identificación UUID (BINARY 16).
 */
interface ExchangeQueueItemInterface
{
    /** * Identificador único.
     * Cambiado a 'mixed' para soportar UUIDs del IdTrait.
     */
    public function getId(): mixed;

    /*
     * -------------------------------------------------------------------------
     * CONFIGURACIÓN Y ENRUTAMIENTO
     * -------------------------------------------------------------------------
     */

    /** Configuración de acceso al canal (API Keys, tokens) */


    public function getConfig(): ?ChannelConfigInterface;

    public function setConfig(?ChannelConfigInterface $config): self;

    /** Definición técnica del destino (path, método) */
    public function getEndpoint(): ?EndpointInterface;

    /** Asigna el endpoint técnico al ítem de la cola */
    public function setEndpoint(?EndpointInterface $endpoint): self;

    /*
     * -------------------------------------------------------------------------
     * CONTROL DE TIEMPOS Y PROGRAMACIÓN
     * -------------------------------------------------------------------------
     */

    /** Fecha programada para la ejecución */
    public function getRunAt(): ?DateTimeInterface;

    public function setRunAt(?DateTimeInterface $at): self;

    public function getRetryCount(): int;

    public function setRetryCount(int $count): self;

    public function getMaxAttempts(): int;

    public function getStatus(): string;
    public function setStatus(string $status): self;

    /*
     * -------------------------------------------------------------------------
     * AUDITORÍA TÉCNICA (RAW HTTP)
     * -------------------------------------------------------------------------
     */

    public function setLastRequestRaw(?string $raw): self;

    public function setLastResponseRaw(?string $raw): self;

    public function setLastHttpCode(?int $code): self;

    public function getLastResponseRaw(): ?string;

    public function getLastHttpCode(): ?int;

    /*
     * -------------------------------------------------------------------------
     * AUDITORÍA DE NEGOCIO Y ESTADOS
     * -------------------------------------------------------------------------
     */

    public function setExecutionResult(?array $result): self;

    public function getExecutionResult(): ?array;

    public function setFailedReason(?string $reason): self;

    public function getFailedReason(): ?string;

    /*
     * -------------------------------------------------------------------------
     * TRANSICIONES DE ESTADO (WORKFLOW)
     * -------------------------------------------------------------------------
     */

    /** Marca el inicio del procesamiento por un Worker */
    public function markProcessing(string $workerId, DateTimeImmutable $now): void;

    /** Marca la finalización exitosa */
    public function markSuccess(DateTimeImmutable $now): void;

    /** Gestiona el fallo y decide la programación del reintento */
    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void;
}