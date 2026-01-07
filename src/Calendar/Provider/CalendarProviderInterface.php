<?php
declare(strict_types=1);

namespace App\Calendar\Provider;

use App\Calendar\Dto\CalendarEventDto;
use App\Calendar\Dto\CalendarResourceDto;
use DateTimeInterface;

/**
 * Contrato para proveedores de calendarios.
 *
 * El controller NO debe saber si los datos vienen de Doctrine, API externa, etc.
 * Solo pide: eventos/recursos para (from,to) según una config.
 *
 * @phpstan-type CalendarConfig array<string,mixed>
 */
interface CalendarProviderInterface
{
    /**
     * Indica si este provider puede manejar la configuración entregada.
     *
     * Importante:
     * - Se llama desde ProviderRegistry.
     * - Si más de un provider soporta la misma config, debe considerarse error de config
     *   (ambigüedad) para evitar resultados impredecibles.
     *
     * @param CalendarConfig $config
     */
    public function supports(array $config): bool;

    /**
     * Devuelve eventos ya mapeados a DTOs de FullCalendar.
     *
     * @param CalendarConfig $config
     * @return list<CalendarEventDto>
     */
    public function getEvents(DateTimeInterface $from, DateTimeInterface $to, array $config): array;

    /**
     * Devuelve recursos (scheduler/resources) ya mapeados a DTOs de FullCalendar.
     *
     * @param CalendarConfig $config
     * @return list<CalendarResourceDto>
     */
    public function getResources(DateTimeInterface $from, DateTimeInterface $to, array $config): array;
}