<?php

declare(strict_types=1);

namespace App\Message\Contract;

/**
 * Contrato estricto que cualquier entidad debe cumplir para poder
 * tener una conversación de chat (Reservas, Viajes, Soporte, etc.).
 */
interface MessageContextInterface
{
    // --- IDENTIFICADORES Y CONTACTO BASE ---
    public function getContextType(): string;
    public function getContextId(): string;
    public function getContextLanguage(): string;
    public function getContextName(): ?string;
    public function getContextPhone(): ?string;

    // --- DICCIONARIO AGNÓSTICO (Para el campo JSON) ---
    public function getOrigin(): ?string;
    public function getStatusTag(): ?string;

    /**
     * Devuelve un diccionario con todas las fechas clave (Hitos).
     * Ej: ['start' => DateTime, 'end' => DateTime, 'booked_at' => DateTime, 'eta' => '14:00']
     * * @return array<string, mixed>
     */
    public function getMilestones(): array;

    public function getItems(): array;
    public function getFinancialTotal(): ?float;
    public function isFinancialCleared(): bool;

    // --- REGLAS DE NEGOCIO DEL CHAT ---
    /**
     * Define si la conversación debe archivarse/cerrarse automáticamente
     * (Ej: Si todos los eventos de la reserva fueron cancelados).
     */
    public function isArchivable(): bool;
}