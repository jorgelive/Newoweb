<?php
declare(strict_types=1);

namespace App\Calendar\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resuelve y centraliza la configuración de calendarios.
 *
 * MODIFICACIÓN:
 * Este servicio ha sido actualizado para soportar configuraciones distribuidas.
 * En lugar de leer únicamente 'parameters.calendars', ahora escanea y fusiona
 * dinámicamente cualquier parámetro que comience con el prefijo "calendar_".
 *
 * Prioridad de carga:
 * 1. Parámetro legacy 'calendars' (si existe).
 * 2. Parámetros modulares 'calendar_*' (ej: calendar_pms, calendar_reserva).
 *
 * Esto permite:
 * - Mantener la configuración dividida en múltiples archivos YAML.
 * - Centralizar la validación de existencia y tipo de array.
 */
final class CalendarConfigResolver
{
    /**
     * Almacena en caché la configuración fusionada para evitar re-escanear
     * el ParameterBag en múltiples llamadas dentro del mismo request.
     *
     * @var array<string, mixed>|null
     */
    private ?array $resolvedConfig = null;

    public function __construct(
        private readonly ParameterBagInterface $params,
    ) {}

    /**
     * Obtiene la configuración específica de un calendario por su clave.
     *
     * @param string $calendarKey La clave única del calendario (ej: 'pms_eventos_no_cancelados').
     *
     * @return array<string, mixed> La configuración del calendario solicitado.
     *
     * @throws HttpException 500 si la clave no existe o no es un array válido.
     */
    public function getConfig(string $calendarKey): array
    {
        // Cargamos y fusionamos las configuraciones si aún no se ha hecho
        if ($this->resolvedConfig === null) {
            $this->resolvedConfig = $this->loadAllConfigurations();
        }

        if (!array_key_exists($calendarKey, $this->resolvedConfig)) {
            throw new HttpException(500, sprintf(
                'El calendario "%s" no fue encontrado en la configuración fusionada (buscando en parameters.calendars y parameters.calendar_*).',
                $calendarKey
            ));
        }

        $cfg = $this->resolvedConfig[$calendarKey];

        if (!is_array($cfg)) {
            throw new HttpException(500, sprintf('La configuración de "%s" debe ser un array válido.', $calendarKey));
        }

        return $cfg;
    }

    /**
     * Escanea el ParameterBag y fusiona todas las configuraciones de calendario.
     *
     * Busca:
     * 1. La clave exacta 'calendars' (compatibilidad).
     * 2. Cualquier clave que empiece por 'calendar_'.
     *
     * @return array<string, mixed>
     */
    private function loadAllConfigurations(): array
    {
        $mergedParams = [];
        $allParams = $this->params->all();

        // 1. Intentar cargar la configuración legacy 'calendars' si existe
        if (isset($allParams['calendars']) && is_array($allParams['calendars'])) {
            $mergedParams = array_merge($mergedParams, $allParams['calendars']);
        }

        // 2. Escanear dinámicamente buscando prefijos 'calendar_'
        foreach ($allParams as $key => $value) {
            // Verificamos prefijo y que sea un array para evitar errores con strings
            if (str_starts_with($key, 'calendars_') && is_array($value)) {
                // Fusionamos al array principal
                $mergedParams = array_merge($mergedParams, $value);
            }
        }

        return $mergedParams;
    }
}