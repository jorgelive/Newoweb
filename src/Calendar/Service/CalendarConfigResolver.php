<?php
declare(strict_types=1);

namespace App\Calendar\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Devuelve la configuración de un calendario desde `parameters.calendars`.
 *
 * Esto permite:
 * - mantener el controller delgado
 * - centralizar validaciones / cambios futuros
 */
final class CalendarConfigResolver
{
    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function getConfig(string $calendarKey): array
    {
        /** @var array<string,mixed> $calendars */
        $calendars = (array) $this->params->get('calendars');

        if (!array_key_exists($calendarKey, $calendars)) {
            throw new HttpException(500, sprintf('El calendario "%s" no está en parameters.calendars.', $calendarKey));
        }

        $cfg = $calendars[$calendarKey];
        if (!is_array($cfg)) {
            throw new HttpException(500, sprintf('La config de "%s" debe ser array.', $calendarKey));
        }

        return $cfg;
    }
}