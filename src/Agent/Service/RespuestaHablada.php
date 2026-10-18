<?php

declare(strict_types=1);

namespace App\Agent\Service;

/**
 * Lo que contesta {@see VoiceAssistant}: el texto para decir y si con él se acaba la conversación.
 *
 * Quién cierra lo decide el MODELO, no el código, porque «no» significa dos cosas: a «¿para dos
 * personas?» es un dato y la conversación sigue; a «¿algo más?» es un cierre. La palabra es la
 * misma y sólo distingue la pregunta anterior, que es del modelo. Lo marca con
 * {@see self::MARCA_DE_CIERRE} al final del texto, y aquí se quita antes de que nadie la oiga.
 *
 * Es seguro dejárselo al modelo porque equivocarse no cuesta: cerrar de más obliga a volver a
 * abrir; no cerrar deja la sesión abierta hasta que Alexa la cierra sola por silencio. El canal es
 * de solo lectura, así que ningún cierre se lleva un dato por delante. Ver docs/AgentVoz.md §4.
 */
final readonly class RespuestaHablada
{
    public const string MARCA_DE_CIERRE = '[[FIN]]';

    public function __construct(
        public string $texto,
        public bool $cierra = false,
    ) {}

    /**
     * Lee la marca y la quita. Tolera las variantes con que un modelo la reescribe —`[FIN]`,
     * `[[ fin ]]`—: si se colara una, Alexa la leería en voz alta.
     */
    public static function delModelo(string $texto): self
    {
        $limpio = preg_replace('/\[\[?\s*FIN\s*\]\]?/iu', '', $texto, -1, $veces);
        $limpio = trim((string) $limpio);

        if ($veces === 0) {
            return new self($texto);
        }

        // Una marca sola, sin despedida: algo tiene que decir el altavoz.
        return new self($limpio === '' ? 'Hasta luego.' : $limpio, true);
    }
}
