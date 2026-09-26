<?php

declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Interface ExchangeQueueItemInterface.
 * Define el ciclo de vida de un ítem en la cola de sincronización.
 * Compatible con identificación UUID (BINARY 16).
 */
interface ExchangeQueueItemInterface
{
    /**
     * Identificador único: el UUID del `IdTrait`, `null` mientras no se ha persistido.
     *
     * Decía `mixed` «para soportar UUIDs», y las siete colas que lo implementan devuelven
     * `?Uuid` desde siempre. Con `mixed`, cada `(string) $item->getId()` del motor —la clave con
     * que se reparten los resultados de un lote— era una conversión a ciegas; con el tipo
     * verdadero, el analizador sabe que un `Uuid` se escribe como texto y que `null` da `''`.
     */
    public function getId(): ?Uuid;

    /*
     * -------------------------------------------------------------------------
     * CONFIGURACIÓN Y ENRUTAMIENTO
     * -------------------------------------------------------------------------
     */

    /** Configuración de acceso al canal (API Keys, tokens) */


    public function getConfig(): ?ChannelConfigInterface;

    public function setConfig(?ChannelConfigInterface $config): self;

    /** Definición técnica del destino (path, método) */
    /**
     * ⚠️ **OBLIGATORIO, aunque el canal no tenga rutas.**
     *
     * Esta firma decía `?EndpointInterface`, y era mentira: `HomogeneousBatch` lo exige no nulo
     * y `AbstractExchangeRepository::claimRunnable()` agrupa los lotes por `(config_id,
     * endpoint_id)` en SQL nativo. Las **seis** colas que ya existían lo tenían `nullable: false`
     * en la base; el `?` no lo ejercía nadie.
     *
     * Hasta que llegó el correo, que no tiene rutas —su destino es un buzón—. El `?` dejó pasar
     * una cola sin endpoint: PHPStan limpio, contenedor limpio, tests en verde… y el primer
     * envío real murió con «Unknown column 'endpoint_id'», con el mensaje ya encolado.
     *
     * Un canal sin rutas usa un endpoint **marcador** —ver el `email_send`—: una fila inerte es
     * más barata que una excepción en el motor, y ahora el contrato obliga a ponerla.
     */
    public function getEndpoint(): EndpointInterface;

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

    /** @param array<string, mixed>|null $result Lo que dejó la ejecución: estado, error, ids… */
    public function setExecutionResult(?array $result): self;

    /** @return array<string, mixed>|null */
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