<?php

declare(strict_types=1);

namespace App\Message\Contract;

use DateTimeInterface;

/**
 * Interface MessageContextInterface
 *
 * Representa cualquier entidad del sistema (Reserva, Tour, Lead) que puede
 * actuar como "disparador" o "dueño" de una conversación de mensajes.
 * Se utiliza principalmente en la capa de EVENTOS para evaluar Reglas.
 *
 * @example
 * // Uso en el evaluador de reglas:
 * if ($context->getContextLanguage() === 'es') { ... }
 */
interface MessageContextInterface
{
    /**
     * Obtiene el identificador del módulo/tipo de este contexto.
     * Sirve como la primera parte del "Join Lógico".
     *
     * @return string Ej: 'pms_reserva', 'tour_booking', 'spa_appointment'
     */
    public function getContextType(): string;

    /**
     * Obtiene el ID único físico de la entidad (Generalmente un UUID).
     * Sirve como la segunda parte del "Join Lógico".
     *
     * @return string Ej: '018dc693-8515-717a-8d07-2c4b5b7b9b1a'
     */
    public function getContextId(): string;

    /**
     * Devuelve el código ISO de idioma principal del contexto para elegir la plantilla adecuada.
     *
     * @return string Código de dos letras (Ej: 'es', 'en', 'fr')
     */
    public function getContextLanguage(): string;

    /**
     * Recupera una fecha clave (hitos) basada en un nombre estándar.
     * Vital para calcular los envíos programados (Ej: "2 días antes del 'start'").
     *
     * @param string $milestoneName Nombre del hito (Ej: 'start', 'end', 'created')
     * @return DateTimeInterface|null La fecha exacta, o null si el hito no existe en este contexto.
     *
     * @example $context->getMilestone('start') // Devuelve la fecha de Check-in
     */
    public function getMilestone(string $milestoneName): ?DateTimeInterface;

    /**
     * Devuelve un diccionario de atributos usados para segmentar y filtrar reglas.
     *
     * @return array<string, mixed>
     *
     * @example
     * return [
     * 'source' => 'booking_com',
     * 'agency_id' => 15,
     * 'vip_level' => 'gold'
     * ];
     */
    public function getSegmentationAttributes(): array;

    /**
     * Obtiene el nombre completo del cliente/huésped principal asociado al contexto.
     * Se usa para crear el "Snapshot" inicial de la conversación.
     *
     * @return string|null Ej: 'Juan Pérez'
     */
    public function getContextName(): ?string;

    /**
     * Obtiene el número de teléfono principal asociado al contexto.
     *
     * @return string|null Ej: '+34600123456'
     */
    public function getContextPhone(): ?string;
}