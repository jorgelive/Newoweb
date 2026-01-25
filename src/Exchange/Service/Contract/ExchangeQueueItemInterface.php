<?php
declare(strict_types=1);

namespace App\Exchange\Service\Contract;

use App\Pms\Entity\Beds24Config;
use App\Pms\Entity\PmsBeds24Endpoint;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Contrato universal para ítems de cola procesables por el motor de Exchange.
 * Define los métodos necesarios para el ciclo de vida (Creation -> Locking -> Processing -> Auditing).
 */
interface ExchangeQueueItemInterface
{
    /** Identificador único del registro en DB */
    public function getId(): ?int;

    // --- Configuración y Enrutamiento ---

    /** Configuración de acceso al canal (API Keys, tokens) */
    public function getBeds24Config(): ?Beds24Config;

    /** Definición técnica del destino (path, método) */
    public function getEndpoint(): ?PmsBeds24Endpoint;

    /** * ✅ NUEVO: Obliga a que la entidad permita asignar el endpoint.
     * Vital para la desnormalización en RatesPush y la creación en Listeners.
     */
    public function setEndpoint(?PmsBeds24Endpoint $endpoint): self;

    // --- Control de Tiempos y Programación ---

    /** Fecha programada para la siguiente ejecución */
    public function getRunAt(): ?DateTimeInterface;

    /** Define cuándo debe volver a ejecutarse el ítem */
    public function setRunAt(?DateTimeInterface $at): self;

    /** Número de intentos realizados */
    public function getRetryCount(): int;

    /** Incrementa o define el contador de intentos */
    public function setRetryCount(int $count): self;

    /** Límite máximo de intentos permitidos antes de morir */
    public function getMaxAttempts(): int;

    // --- Auditoría Técnica RAW (HTTP Body) ---

    /** Guarda el cuerpo exacto enviado a la API */
    public function setLastRequestRaw(?string $raw): self;

    /** Guarda el cuerpo exacto recibido de la API */
    public function setLastResponseRaw(?string $raw): self;

    /** Guarda el código de estado HTTP (200, 401, 500, etc.) */
    public function setLastHttpCode(?int $code): self;

    /** Recupera la última respuesta para el fallback SQL */
    public function getLastResponseRaw(): ?string;

    /** Recupera el último código HTTP para auditoría */
    public function getLastHttpCode(): ?int;

    // --- Auditoría de Negocio (JSON Procesado) ---

    /** Guarda un resumen estructurado del resultado del Handler */
    public function setExecutionResult(?array $result): self;

    /** Recupera el resumen del resultado */
    public function getExecutionResult(): ?array;

    // --- Gestión de Estados y Errores ---

    /** Define el mensaje de error legible */
    public function setFailedReason(?string $reason): self;

    /** Recupera el último mensaje de fallo */
    public function getFailedReason(): ?string;

    // --- Transiciones de Estado (Workflow) ---

    /** Transición: marca el ítem como tomado por un worker */
    public function markProcessing(string $workerId, DateTimeImmutable $now): void;

    /** Transición: marca el ítem como completado con éxito */
    public function markSuccess(DateTimeImmutable $now): void;

    /** Transición: marca fallo y programa reintento o muerte */
    public function markFailure(string $reason, ?int $httpCode, DateTimeImmutable $nextRetry): void;
}