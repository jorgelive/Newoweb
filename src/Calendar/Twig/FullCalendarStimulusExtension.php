<?php
declare(strict_types=1);

namespace App\Calendar\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Extension de Twig para integrar FullCalendar con Stimulus.
 * * Se encarga de generar el contenedor HTML y los atributos de datos necesarios
 * para inicializar el controlador de Stimulus 'fullcalendar'.
 * Gestiona la generación de URLs absolutas para eventos y recursos dinámicamente,
 * asegurando que la codificación JSON sea segura para atributos HTML.
 */
class FullCalendarStimulusExtension extends AbstractExtension
{
    /**
     * @var RouterInterface Servicio de enrutamiento para generar URLs de API.
     */
    private RouterInterface $_router;

    /**
     * Constructor de la extensión.
     *
     * @param RouterInterface $router Interfaz del router de Symfony.
     */
    public function __construct(RouterInterface $router)
    {
        $this->_router = $router;
    }

    /**
     * Devuelve el nombre de la extensión para registro en Twig.
     *
     * @return string Nombre identificador de la extensión.
     */
    public function getName(): string
    {
        return 'fullcalendar_stimulus';
    }

    /**
     * Define las funciones personalizadas de Twig disponibles en las plantillas.
     *
     * @return TwigFunction[] Array de funciones Twig.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'fullcalendar_stimulus',
                [$this, 'renderStimulus'],
                ['is_safe' => ['html']] // Permite renderizar HTML sin escapar, vital para los div y atributos data
            ),
        ];
    }

    // =========================
    // URLs Generator Logic
    // =========================

    /**
     * Genera la URL absoluta para la carga de eventos de un calendario específico.
     * Verifica primero si la ruta existe en la colección de rutas de Symfony.
     *
     * @param mixed $calendar Identificador del calendario (usualmente string o int).
     * @return string|null La URL absoluta o null si la ruta no está definida.
     */
    private function generateUrl($calendar): ?string
    {
        // Verificación de existencia de la ruta para evitar errores fatales en runtime
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

    /**
     * Genera la URL absoluta para la carga de recursos (filas del timeline).
     *
     * @param mixed $calendar Identificador del calendario.
     * @return string|null La URL absoluta o null si la ruta no está definida.
     */
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
     * Renderiza el contenedor HTML necesario para activar el controlador Stimulus.
     *
     * Genera atributos `data-fullcalendar-*-value` codificados en JSON seguros
     * para ser interpretados automáticamente por los `static values` de Stimulus.
     *
     * @param string $caller            Identificador único para el ID del DOM (ej. 'main', 'modal').
     * @param array|string $calendars   Array de calendarios o string único.
     * @param string|null $defaultView  Vista inicial (ej. 'dayGridMonth', 'resourceTimelineOneMonth').
     * @param array $views              Lista de vistas permitidas en el header toolbar.
     * @param bool|int $allDaySlot      Booleano para slot de todo el día, o entero si se usa firma corta (legacy).
     * @param int $resourceAreaWidth    Ancho de la columna de recursos en píxeles (default 120).
     *
     * @return string HTML del componente listo para inyectar en la plantilla.
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
        // Smart arguments logic
        // =========================
        // Detección de firma antigua: si el 5to argumento es numérico, es el ancho.
        if (is_numeric($allDaySlot)) {
            $resourceAreaWidth = (int) $allDaySlot;
            $allDaySlot = false;
        }

        // Validación estricta del ancho del área de recursos
        if (!is_numeric($resourceAreaWidth) || (int)$resourceAreaWidth <= 0) {
            $resourceAreaWidth = 120;
        }
        $resourceAreaWidth = (int) $resourceAreaWidth;

        // Valor por defecto para la vista inicial
        if (empty($defaultView)) {
            $defaultView = 'dayGridMonth';
        }

        // =========================
        // Calendars normalization
        // =========================
        // Normalización a array si se recibe un string único
        if (!is_array($calendars) && is_string($calendars)) {
            $calendars = ['Default' => $calendars];
        }

        // Construcción de la configuración que consumirá el JS
        $calendarsConfig = [];
        foreach ($calendars as $key => $calendar) {
            // El key es el nombre visible si es string, sino usamos el valor
            $nombre = is_string($key) ? $key : $calendar;

            $calendarsConfig[] = [
                'nombre'      => $nombre,
                'eventUrl'    => $this->generateUrl($calendar),
                'resourceUrl' => $this->generateResourceUrl($calendar),
            ];
        }

        // =========================
        // JSON Encoding & Security
        // =========================
        // JSON_HEX_APOS es CRÍTICO: convierte ' en \u0027 para no romper el atributo HTML '...'
        // JSON_UNESCAPED_UNICODE: mantiene caracteres latinos (ñ, tildes) legibles.
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

        // Conversión a string 'true'/'false' para que Stimulus lo parsee como booleano
        $allDaySlotValue = $allDaySlot ? 'true' : 'false';

        // =========================
        // HTML Output
        // =========================
        // Inyección de variables en sintaxis HEREDOC.
        // Los atributos coinciden con: static values = { calendars: Array, views: Object, ... } en JS.
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