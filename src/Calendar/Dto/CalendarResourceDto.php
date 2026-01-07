<?php
declare(strict_types=1);

namespace App\Calendar\Dto;

use JsonSerializable;

/**
 * DTO de Resource para FullCalendar Scheduler.
 *
 * FullCalendar espera mínimo:
 * - id
 * - title
 *
 * Todo lo extra lo puedes enviar en `extendedProps` y en JS cae como:
 * resource.extendedProps.*
 */
final class CalendarResourceDto implements JsonSerializable
{
    /**
     * @param array<string,mixed>|null $extendedProps
     */
    public function __construct(
        public readonly string|int $id,
        public readonly string $title,
        public readonly ?array $extendedProps = null,
    ) {}

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    public function jsonSerialize(): array
    {
        $out = [
            'id' => $this->id,
            'title' => $this->title,
        ];

        if (!empty($this->extendedProps)) {
            $out['extendedProps'] = $this->extendedProps;
        }

        return $out;
    }
}