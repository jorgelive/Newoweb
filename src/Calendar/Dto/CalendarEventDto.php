<?php

declare(strict_types=1);

namespace App\Calendar\Dto;

use DateTimeInterface;
use JsonSerializable;

/**
 * DTO de Event para FullCalendar.
 * Soporta identificadores de tipo objeto (UUID) para evitar casteos manuales en los proveedores.
 *
 * ⚠️ El alias iba escrito `* * @phpstan-type` —en la misma línea y con un asterisco de más—, así
 * que PHPStan no lo registraba y `$tooltip` seguía sin tipo pese a estar declarado aquí.
 *
 * @phpstan-type TooltipType string|list<string>|null
 */
final class CalendarEventDto implements JsonSerializable
{
    /**
     * @param string|int|\Stringable $id Identificador único. `Stringable` y no `object`:
     *        lo que se pide es que se pueda pasar a texto, y un `Uuid` lo cumple.
     * @param string $title Título visual en el calendario.
     * @param DateTimeInterface $start Fecha y hora de inicio.
     * @param DateTimeInterface $end Fecha y hora de fin.
     * @param string|int|\Stringable|null $resourceId ID de la unidad/recurso (soporta UUID).
     * @param TooltipType $tooltip Información extra para el hover.
     * @param list<string>|null $classNames Clases CSS que FullCalendar pone en el evento.
     * @param array<string, mixed>|null $extendedProps Diccionario abierto que lee el front; el
     *        contrato de su forma lo fija quien construye el evento, no este DTO.
     */
    public function __construct(
        public readonly string|int|\Stringable $id,
        public readonly string $title,
        public readonly DateTimeInterface $start,
        public readonly DateTimeInterface $end,
        public readonly string|int|\Stringable|null $resourceId = null,
        public readonly ?string $textColor = null,
        public readonly ?string $backgroundColor = null,
        public readonly ?string $borderColor = null,
        public readonly ?string $color = null,
        public readonly ?array $classNames = null,
        public readonly ?string $urledit = null,
        public readonly ?string $urlshow = null,
        public readonly string|array|null $tooltip = null,
        public readonly ?int $prioridadImportante = null,
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
            'start' => $this->start->format('Y-m-d\\TH:i:s'),
            'end' => $this->end->format('Y-m-d\\TH:i:s'),
        ];

        if ($this->resourceId !== null) {
            $out['resourceId'] = (string) $this->resourceId;
        }

        if ($this->textColor !== null) $out['textColor'] = $this->textColor;
        if ($this->backgroundColor !== null) $out['backgroundColor'] = $this->backgroundColor;
        if ($this->borderColor !== null) $out['borderColor'] = $this->borderColor;
        if ($this->color !== null) $out['color'] = $this->color;

        if (!empty($this->classNames)) {
            // Sin `array_values()`: `$classNames` ya es `list<string>` desde que está tipado, y
            // FullCalendar necesita un array JSON, no un objeto. El reindexado sobraba.
            $out['classNames'] = $this->classNames;
        }

        if ($this->urledit !== null) $out['urledit'] = $this->urledit;
        if ($this->urlshow !== null) $out['urlshow'] = $this->urlshow;

        if ($this->tooltip !== null && $this->tooltip !== '' && $this->tooltip !== []) {
            $out['tooltip'] = $this->tooltip;
        }

        if ($this->prioridadImportante !== null) {
            $out['prioridadImportante'] = $this->prioridadImportante;
        }

        if (!empty($this->extendedProps)) {
            $out['extendedProps'] = $this->extendedProps;
        }

        return $out;
    }
}