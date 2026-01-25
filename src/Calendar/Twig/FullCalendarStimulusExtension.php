<?php
declare(strict_types=1);

namespace App\Calendar\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class FullCalendarStimulusExtension extends AbstractExtension
{
    private RouterInterface $_router;

    public function __construct(RouterInterface $router)
    {
        $this->_router = $router;
    }

    public function getName(): string
    {
        return 'fullcalendar_stimulus';
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'fullcalendar_stimulus',
                [$this, 'renderStimulus'],
                ['is_safe' => ['html']]
            ),
        ];
    }

    // =========================
    // URLs
    // =========================
    private function generateUrl($calendar): ?string
    {
        $exists = $this->_router
            ->getRouteCollection()
            ->get('app_fullcalendar_load_event');

        return $exists
            ? $this->_router->generate(
                'app_fullcalendar_load_event',
                ['calendar' => $calendar],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
            : null;
    }

    private function generateResourceUrl($calendar): ?string
    {
        $exists = $this->_router
            ->getRouteCollection()
            ->get('app_fullcalendar_load_resource');

        return $exists
            ? $this->_router->generate(
                'app_fullcalendar_load_resource',
                ['calendar' => $calendar],
                UrlGeneratorInterface::ABSOLUTE_URL
            )
            : null;
    }

    /**
     * FIRMA FINAL (compatible hacia atrás):
     *
     * fullcalendar_stimulus(
     *   caller,
     *   calendars,
     *   defaultView = null,
     *   views = [],
     *   allDaySlot = false,
     *   resourceAreaWidth = 120
     * )
     *
     * 💡 Si el 5.º parámetro es numérico:
     *    → se interpreta como resourceAreaWidth
     *    → allDaySlot = false
     */
    public function renderStimulus(
        $caller,
        $calendars,
        $defaultView = null,
        $views = [],
        $allDaySlot = false,
        $resourceAreaWidth = 120
    ): string {

        // =========================
        // Smart arguments
        // =========================
        if (is_numeric($allDaySlot)) {
            // Llamada corta: ..., views, 180
            $resourceAreaWidth = (int) $allDaySlot;
            $allDaySlot = false;
        }

        if (!is_numeric($resourceAreaWidth) || (int)$resourceAreaWidth <= 0) {
            $resourceAreaWidth = 120;
        }

        $resourceAreaWidth = (int) $resourceAreaWidth;

        if (empty($defaultView)) {
            $defaultView = 'dayGridMonth';
        }

        // =========================
        // Calendars normalization
        // =========================
        if (!is_array($calendars) && is_string($calendars)) {
            $calendars = ['Default' => $calendars];
        }

        $calendarsConfig = [];
        foreach ($calendars as $key => $calendar) {
            $nombre = is_string($key) ? $key : $calendar;
            $calendarsConfig[] = [
                'nombre'      => $nombre,
                'eventUrl'    => $this->generateUrl($calendar),
                'resourceUrl' => $this->generateResourceUrl($calendar),
            ];
        }

        // =========================
        // JSON encoding
        // =========================
        $calendarsJson = json_encode(
            $calendarsConfig,
            JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_HEX_AMP
            | JSON_UNESCAPED_UNICODE
        );

        $viewsJson = json_encode(
            $views,
            JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_HEX_AMP
            | JSON_UNESCAPED_UNICODE
        ) ?: '{}';

        $allDaySlotValue = $allDaySlot ? 'true' : 'false';

        // =========================
        // HTML output
        // =========================
        return <<<HTML
<div
    id="calendar-{$caller}"
    class="calendar-stimulus-wrapper"
    data-controller="fullcalendar"
    data-fullcalendar-calendars-value='{$calendarsJson}'
    data-fullcalendar-default-view-value="{$defaultView}"
    data-fullcalendar-views-value='{$viewsJson}'
    data-fullcalendar-all-day-slot-value="{$allDaySlotValue}"
    data-fullcalendar-resource-area-width-value="{$resourceAreaWidth}"
    style="min-height: 800px; position: relative;"
>
    <div class="d-flex justify-content-center align-items-center" style="height: 100%; min-height: 400px;">
        <p class="text-muted">Cargando calendario...</p>
    </div>
</div>
HTML;
    }
}