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
    /**
     * @param array<string, mixed> $extendedProps
     */
    public function __construct(
        public readonly string|int|\Stringable $id,
        public readonly string $title,
        public readonly int $orden = 0,
        public readonly ?array $extendedProps = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * @return array<string, mixed>
     */
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