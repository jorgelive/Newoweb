<?php

declare(strict_types=1);

namespace App\Calendar\Config;

use App\Dto\Lee;

/**
 * La configuración de UN calendario, leída una vez del YAML (`parameters.calendars_*`, ver
 * `config/services/services_parameters_pms.yaml`) y ya tipada para los providers.
 *
 * ```
 * YAML ──► CalendarConfigResolver ──► ConfiguracionCalendario::fromArray()  ──► ProviderRegistry
 *                                      └─ único sitio que toca el array crudo     └─► Provider
 * ```
 *
 * No la escribe un tercero, pero el problema era el mismo que en una frontera: cada provider leía
 * `$config['fields']['start']` a mano, con su propio `(string)` y su propio valor por defecto, y el
 * nivel 9 de PHPStan veía ahí ~170 `mixed`. Ahora se lee aquí, con la semántica de siempre.
 *
 * ── Qué NO decide este DTO ─────────────────────────────────────────────────────
 * **Si la configuración es válida.** Leerla nunca lanza: lo que falta o no tiene el tipo esperado
 * queda en `null` (o en su valor por defecto), y es el provider el que rechaza con su mensaje de
 * siempre —«tarifa_ranges_raw requiere fields.price»—. Validar aquí adelantaría el 500 a TODOS
 * los calendarios por culpa de uno, y cambiaría los mensajes que ya conoce quien lee el log.
 *
 * ── La regla de lectura ────────────────────────────────────────────────────────
 * Un valor de otro tipo es un valor que no está, y toma el defecto. Con el YAML real no cambia nada
 * (`probar-calendario-config.php` lo compara contra la base local); con uno roto, lo que antes era
 * un `(string)` de un array —«Array» y un warning— ahora es el valor por defecto.
 */
final class ConfiguracionCalendario
{
    /**
     * @param list<string> $claves Las claves de primer nivel, para el mensaje de «no hay provider».
     */
    public function __construct(
        public readonly array $claves = [],
        public readonly ?string $provider = null,
        // `array_key_exists`, no `isset`: el provider Doctrine se aparta con que la clave EXISTA,
        // aunque valga `null`. Es su forma de no pisar a los providers explícitos.
        public readonly bool $declaraProvider = false,
        // Tal cual: `''` es una entidad declarada y vacía, que `supports()` acepta y `assertConfig()`
        // rechaza con su mensaje. Colapsarla a `null` cambiaría el error por «no hay provider».
        public readonly ?string $entidad = null,
        // El `current_page` en base64 que manda el panel legacy, para el `returnTo` de los enlaces.
        // Lo pone el controlador en cada petición (ver {@see self::conRetorno()}).
        public readonly ?string $retorno = null,
        public readonly ?CamposDeTarifa $campos = null,
        public readonly FiltrosDeCalendario $filtros = new FiltrosDeCalendario(),
        public readonly OpcionesDeEvento $evento = new OpcionesDeEvento(),
        public readonly HorasDeEvento $horas = new HorasDeEvento(),
        public readonly OpcionesDeCatalogo $recursos = new OpcionesDeCatalogo(),
        // El `url` en la RAÍZ: el compactado legacy lo usa si no hay `event.url`.
        public readonly ?EnlacesDeEvento $enlacesRaiz = null,
        public readonly OpcionesDoctrineLegacy $doctrine = new OpcionesDoctrineLegacy(),
    ) {}

    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): self
    {
        $provider = $datos['provider'] ?? null;
        $entidad = $datos['entity'] ?? null;

        return new self(
            claves: array_map(static fn (int|string $clave): string => (string) $clave, array_keys($datos)),
            // Sólo texto: `supports()` compara con `===` contra un nombre, y nada más casa.
            provider: is_string($provider) ? $provider : null,
            declaraProvider: array_key_exists('provider', $datos),
            entidad: is_string($entidad) ? $entidad : null,
            retorno: Lee::texto($datos['runtime_returnTo'] ?? null),
            campos: is_array($datos['fields'] ?? null) ? CamposDeTarifa::fromArray($datos['fields']) : null,
            filtros: FiltrosDeCalendario::desde($datos['filters'] ?? null),
            evento: OpcionesDeEvento::desde($datos['event'] ?? null),
            horas: HorasDeEvento::desde($datos['eventTime'] ?? null),
            recursos: OpcionesDeCatalogo::desde($datos['resources'] ?? null),
            enlacesRaiz: is_array($datos['url'] ?? null) ? EnlacesDeEvento::fromArray($datos['url']) : null,
            doctrine: OpcionesDoctrineLegacy::fromArray($datos),
        );
    }

    /**
     * La misma configuración con el `current_page` de ESTA petición.
     *
     * El resolver guarda la configuración leída; la de la petición no se escribe encima, se copia.
     */
    public function conRetorno(string $retorno): self
    {
        $claves = $this->claves;
        if (!in_array('runtime_returnTo', $claves, true)) {
            // Antes era una clave más del array, y así salía en el mensaje de «no hay provider».
            $claves[] = 'runtime_returnTo';
        }

        return new self(
            claves: $claves,
            provider: $this->provider,
            declaraProvider: $this->declaraProvider,
            entidad: $this->entidad,
            retorno: $retorno,
            campos: $this->campos,
            filtros: $this->filtros,
            evento: $this->evento,
            horas: $this->horas,
            recursos: $this->recursos,
            enlacesRaiz: $this->enlacesRaiz,
            doctrine: $this->doctrine,
        );
    }
}
