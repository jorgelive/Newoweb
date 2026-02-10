<?php

declare(strict_types=1);

namespace App\Calendar\Dto;

use DateTimeInterface;
use JsonSerializable;

/**
 * DTO de Event para FullCalendar.
 * Soporta identificadores de tipo objeto (UUID) para evitar casteos manuales en los proveedores.
 * * @phpstan-type TooltipType string|list<string>|null
 */
final class CalendarEventDto implements JsonSerializable
{
    /**
     * @param string|int|object $id Identificador único (soporta UUID).
     * @param string $title Título visual en el calendario.
     * @param DateTimeInterface $start Fecha y hora de inicio.
     * @param DateTimeInterface $end Fecha y hora de fin.
     * @param string|int|object|null $resourceId ID de la unidad/recurso (soporta UUID).
     * @param string|array|null $tooltip Información extra para el hover.
     */
    public function __construct(
        public readonly string|int|object $id,
        public readonly string $title,
        public readonly DateTimeInterface $start,
        public readonly DateTimeInterface $end,
        public readonly string|int|object|null $resourceId = null,
        public readonly ?string $textColor = null,
        public readonly ?string $backgroundColor = null,
        public readonly ?string $borderColor = null,
        public readonly ?string $color = null,
        public readonly ?array $classNames = null,
        public readonly ?string $urledit = null,
        public readonly ?string $urlshow = null,
        public readonly string|array|null $tooltip = null,
        public readonly ?int $prioridadImportante = null,
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
            'start' => $this->start->format('Y-m-d\\TH:i:sP'),
            'end' => $this->end->format('Y-m-d\\TH:i:sP'),
        ];

        if ($this->resourceId !== null) {
            $out['resourceId'] = (string) $this->resourceId;
        }

        if ($this->textColor !== null) $out['textColor'] = $this->textColor;
        if ($this->backgroundColor !== null) $out['backgroundColor'] = $this->backgroundColor;
        if ($this->borderColor !== null) $out['borderColor'] = $this->borderColor;
        if ($this->color !== null) $out['color'] = $this->color;

        if (!empty($this->classNames)) {
            $out['classNames'] = array_values($this->classNames);
        }

        if ($this->urledit !== null) $out['urledit'] = $this->urledit;
        if ($this->urlshow !== null) $out['urlshow'] = $this->urlshow;

        if ($this->tooltip !== null && $this->tooltip !== '' && $this->tooltip !== []) {
            $out['tooltip'] = $this->tooltip;
        }

        if ($this->prioridadImportante !== null) {
            $out['prioridadImportante'] = $this->prioridadImportante;
        }

        return $out;
    }
}