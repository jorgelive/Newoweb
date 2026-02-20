<?php

declare(strict_types=1);

namespace App\Message\Contract;

use DateTimeInterface;

/**
 * Contrato universal para cualquier entidad que pueda recibir mensajes.
 */
interface MessageContextInterface
{
    public function getContextName(): ?string;
    public function getContextPhone(): ?string;
    public function getContextEmail(): ?string;
    public function getContextLanguage(): string;
    public function getMilestone(string $milestoneName): ?DateTimeInterface;

    /**
     * @return array<string, mixed>
     */
    public function getTemplateVariables(): array;

    /**
     * 🔥 NUEVO: Devuelve identificadores externos y datos técnicos de enrutamiento.
     * (Ej: IDs de PMS, IDs de pasarelas de pago, tokens de canales).
     * @return array<string, mixed>
     */
    public function getContextMetadata(): array;
}