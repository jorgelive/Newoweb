<?php

declare(strict_types=1);

namespace App\Agent\Provider\DeepSeek;

use App\Agent\Provider\Dto\ConsumoDeTokens;
use App\Agent\Provider\Dto\LlamadaAHerramienta;
use App\Agent\Provider\Dto\RespuestaDelModelo;
use App\Dto\Lee;

/**
 * La respuesta de `/chat/completions` de DeepSeek, leída en {@see RespuestaDelModelo}.
 *
 * Es el único sitio que toca el JSON de DeepSeek; {@see DeepSeekEngine} trabaja con lo que sale de
 * aquí. Cada campo se lee como lo leía el motor antes, para que el comportamiento —y las cifras de
 * consumo del log— no cambien: ver `DeepSeekRespuestaTest`.
 *
 * ```
 * { choices: [ { message: { content, tool_calls: [ {id, function: {name, arguments}} ] },
 *                finish_reason } ],
 *   usage: { prompt_cache_hit_tokens, prompt_cache_miss_tokens, completion_tokens } }
 * ```
 */
final class DeepSeekRespuesta
{
    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): RespuestaDelModelo
    {
        $eleccion = Lee::en($datos, 'choices', 0);
        $mensaje = Lee::en($eleccion, 'message');
        $uso = Lee::en($datos, 'usage');

        $llamadas = [];
        foreach (Lee::listaDeMapas(Lee::en($mensaje, 'tool_calls')) as $llamada) {
            // Los argumentos llegan como CADENA JSON, no como objeto. Un modelo puede mandar JSON
            // roto: entonces van vacíos, y la skill contesta que le falta algo en vez de reventar
            // el turno.
            $argumentos = json_decode(Lee::texto(Lee::en($llamada, 'function', 'arguments')) ?? '{}', true);

            $llamadas[] = new LlamadaAHerramienta(
                id: Lee::texto($llamada['id'] ?? null) ?? '',
                nombre: Lee::texto(Lee::en($llamada, 'function', 'name')) ?? '',
                argumentos: LlamadaAHerramienta::objetoJson($argumentos),
            );
        }

        // DeepSeek no da la entrada total en un campo que se leyera: la parte en acierto y fallo
        // de caché, que es lo que el log registraba. Se reconstruye de sus dos mitades para que
        // `entradaSinCache()` devuelva exactamente el `prompt_cache_miss_tokens` de siempre.
        $acierto = Lee::entero(Lee::en($uso, 'prompt_cache_hit_tokens')) ?? 0;
        $fallo = Lee::entero(Lee::en($uso, 'prompt_cache_miss_tokens')) ?? 0;

        return new RespuestaDelModelo(
            hayTurno: is_array($mensaje),
            // `content` es `null` cuando el turno sólo trae llamadas: eso es texto vacío.
            texto: Lee::texto(Lee::en($mensaje, 'content')) ?? '',
            llamadas: $llamadas,
            motivoFin: Lee::texto(Lee::en($eleccion, 'finish_reason')) ?? '',
            motivoBloqueo: null,
            consumo: new ConsumoDeTokens(
                entrada: $acierto + $fallo,
                cacheLeido: $acierto,
                salida: Lee::entero(Lee::en($uso, 'completion_tokens')),
            ),
            turnoCrudo: is_array($mensaje) ? $mensaje : [],
        );
    }
}
