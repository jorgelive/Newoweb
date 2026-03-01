<?php

declare(strict_types=1);

namespace App\Calendar\Dto;

use JsonSerializable;

/**
 * DTO de Resource para FullCalendar Scheduler.
 * Permite IDs de tipo objeto para compatibilidad nativa con UUIDs.
 */
final class CalendarResourceDto implements JsonSerializable
{
    public function __construct(
        public readonly string|int|object $id,
        public readonly string $title,
        public readonly int $orden = 0,
        public readonly ?array $extendedProps = null,
    ) {}

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    public function jsonSerialize(): array
    {
        $out = [
            'id' => (string) $this->id,
            'title' => $this->title,
            'orden' => $this->orden
        ];

        if (!empty($this->extendedProps)) {
            $out['extendedProps'] = $this->extendedProps;
        }


        return $out;
    }
}