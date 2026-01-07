<?php
declare(strict_types=1);

namespace App\Calendar\Dto;

use DateTimeInterface;
use JsonSerializable;

/**
 * DTO de Event para FullCalendar.
 *
 * Notas:
 * - FullCalendar mueve claves desconocidas a `extendedProps`, pero tu frontend
 *   ya consume algunas claves planas (urledit/urlshow/tooltip), así que las emitimos igual.
 *
 * @phpstan-type TooltipType string|list<string>|null
 */
final class CalendarEventDto implements JsonSerializable
{
    /**
     * @param list<string>|null $classNames
     * @param TooltipType $tooltip
     */
    public function __construct(
        public readonly string|int $id,
        public readonly string $title,
        public readonly DateTimeInterface $start,
        public readonly DateTimeInterface $end,

        public readonly string|int|null $resourceId = null,

        public readonly ?string $textColor = null,
        public readonly ?string $backgroundColor = null,
        public readonly ?string $borderColor = null,
        public readonly ?string $color = null,

        /** @var list<string>|null */
        public readonly ?array $classNames = null,

        public readonly ?string $urledit = null,
        public readonly ?string $urlshow = null,

        /** @var TooltipType */
        public readonly string|array|null $tooltip = null,
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
            'start' => $this->start->format('Y-m-d\\TH:i:sP'),
            'end' => $this->end->format('Y-m-d\\TH:i:sP'),
        ];

        if ($this->resourceId !== null) {
            $out['resourceId'] = $this->resourceId;
        }

        if ($this->textColor !== null) {
            $out['textColor'] = $this->textColor;
        }
        if ($this->backgroundColor !== null) {
            $out['backgroundColor'] = $this->backgroundColor;
        }
        if ($this->borderColor !== null) {
            $out['borderColor'] = $this->borderColor;
        }
        if ($this->color !== null) {
            $out['color'] = $this->color;
        }

        if (!empty($this->classNames)) {
            $out['classNames'] = array_values($this->classNames);
        }

        if ($this->urledit !== null) {
            $out['urledit'] = $this->urledit;
        }
        if ($this->urlshow !== null) {
            $out['urlshow'] = $this->urlshow;
        }

        if ($this->tooltip !== null && $this->tooltip !== '' && $this->tooltip !== []) {
            $out['tooltip'] = $this->tooltip;
        }

        return $out;
    }
}