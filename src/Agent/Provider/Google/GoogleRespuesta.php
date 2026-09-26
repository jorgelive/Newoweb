<?php

declare(strict_types=1);

namespace App\Agent\Provider\Google;

use App\Agent\Provider\Dto\ConsumoDeTokens;
use App\Agent\Provider\Dto\LlamadaAHerramienta;
use App\Agent\Provider\Dto\RespuestaDelModelo;
use App\Dto\Lee;

/**
 * La respuesta de `generateContent` de Gemini, leída en {@see RespuestaDelModelo}.
 *
 * Es el único sitio que toca el JSON de Google. La leen los dos que hablan con Gemini: el motor
 * del agente ({@see GoogleAIEngine}) y el lector de documentos
 * ({@see \App\Agent\Vision\GoogleLectorDeImagen}), que antes la recorrían cada uno a su manera.
 *
 * ```
 * { candidates: [ { content: { parts: [ {text} | {functionCall: {name, args}} ] }, finishReason } ],
 *   promptFeedback: { blockReason },
 *   usageMetadata: { promptTokenCount, cachedContentTokenCount, thoughtsTokenCount,
 *                    candidatesTokenCount } }
 * ```
 *
 * ⚠️ En `Part`, `text` y `functionCall` son un `oneof` del proto: una parte trae uno u otro. Si
 * alguna vez trajera los dos, manda la llamada — que es lo que hacía el bucle del motor.
 */
final class GoogleRespuesta
{
    /** @param array<mixed> $datos */
    public static function fromArray(array $datos): RespuestaDelModelo
    {
        $candidato = Lee::en($datos, 'candidates', 0);
        $partes = Lee::en($candidato, 'content', 'parts');
        $uso = Lee::en($datos, 'usageMetadata');

        $texto = '';
        $llamadas = [];
        foreach (Lee::listaDeMapas($partes) as $parte) {
            $llamada = $parte['functionCall'] ?? null;

            if (is_array($llamada)) {
                $llamadas[] = new LlamadaAHerramienta(
                    id: '',
                    nombre: Lee::texto($llamada['name'] ?? null) ?? '',
                    // Gemini manda `args` como objeto (`Struct`), no como cadena: se toma tal cual.
                    argumentos: LlamadaAHerramienta::objetoJson($llamada['args'] ?? null),
                );
            } elseif (is_string($parte['text'] ?? null)) {
                $texto .= $parte['text'];
            }
        }

        return new RespuestaDelModelo(
            hayTurno: is_array($candidato),
            texto: $texto,
            llamadas: $llamadas,
            motivoFin: Lee::texto(Lee::en($candidato, 'finishReason')) ?? '',
            motivoBloqueo: Lee::texto(Lee::en($datos, 'promptFeedback', 'blockReason')),
            consumo: new ConsumoDeTokens(
                entrada: Lee::entero(Lee::en($uso, 'promptTokenCount')) ?? 0,
                cacheLeido: Lee::entero(Lee::en($uso, 'cachedContentTokenCount')) ?? 0,
                pensamiento: Lee::entero(Lee::en($uso, 'thoughtsTokenCount')),
                salida: Lee::entero(Lee::en($uso, 'candidatesTokenCount')),
            ),
            turnoCrudo: is_array($partes) ? $partes : [],
        );
    }
}
