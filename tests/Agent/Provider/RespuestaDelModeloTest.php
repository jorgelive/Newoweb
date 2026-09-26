<?php

declare(strict_types=1);

namespace App\Tests\Agent\Provider;

use App\Agent\Provider\DeepSeek\DeepSeekRespuesta;
use App\Agent\Provider\Dto\ConsumoDeTokens;
use App\Agent\Provider\Dto\LlamadaAHerramienta;
use App\Agent\Provider\Dto\RespuestaDelModelo;
use App\Agent\Provider\Google\GoogleRespuesta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las respuestas de DeepSeek y de Gemini leídas en {@see RespuestaDelModelo} dicen LO MISMO que las
 * expresiones crudas a las que sustituyen.
 *
 * No hay respuestas crudas guardadas en ningún sitio —ni en la base ni en `info.log`, que sólo tiene
 * las cifras ya calculadas—, así que la comparación va contra payloads con la forma que documenta
 * cada proveedor y que el código ya esperaba. Cada `lecturaVieja*()` es copia literal de lo que
 * hacía el motor antes del DTO; si un día se toca el `fromArray()` y no casan, el cambio se ve aquí.
 *
 * ⚠️ Las cifras de consumo son las de las líneas de `info.log` con las que se mide el coste real del
 * agente (docs/Agent.md §3.4): que no cambien es la mitad del propósito de este test.
 */
#[CoversClass(DeepSeekRespuesta::class)]
#[CoversClass(GoogleRespuesta::class)]
#[CoversClass(RespuestaDelModelo::class)]
#[CoversClass(ConsumoDeTokens::class)]
#[CoversClass(LlamadaAHerramienta::class)]
final class RespuestaDelModeloTest extends TestCase
{
    // ── DeepSeek ─────────────────────────────────────────────────────────────

    /** @return iterable<string, array{array<mixed>}> */
    public static function respuestasDeDeepSeek(): iterable
    {
        yield 'llamada a herramienta, content null' => [[
            'id' => 'b2c4-…',
            'object' => 'chat.completion',
            'created' => 1727312345,
            'model' => 'deepseek-v4-flash',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_0_7f3a',
                        'type' => 'function',
                        'function' => [
                            'name' => 'consultar_disponibilidad',
                            'arguments' => '{"desde":"2026-10-01","hasta":"2026-10-03","pax":4}',
                        ],
                    ]],
                ],
                'logprobs' => null,
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 6130,
                'completion_tokens' => 41,
                'total_tokens' => 6171,
                'prompt_tokens_details' => ['cached_tokens' => 5888],
                'prompt_cache_hit_tokens' => 5888,
                'prompt_cache_miss_tokens' => 242,
            ],
            'system_fingerprint' => 'fp_8802369eaa_prod0623',
        ]];

        yield 'texto final' => [[
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => "  La casita 3 está libre del 1 al 3.\n"],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_cache_hit_tokens' => 6144, 'prompt_cache_miss_tokens' => 310, 'completion_tokens' => 58],
        ]];

        yield 'dos llamadas, una con JSON roto y otra sin argumentos' => [[
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Déjame consultar…',
                    'tool_calls' => [
                        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'buscar_reserva', 'arguments' => '{"localizador": "V4J']],
                        ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'consultar_tipo_cambio', 'arguments' => '{}']],
                    ],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 7020, 'completion_tokens' => 77],
        ]];

        yield 'filtrada' => [[
            'choices' => [['message' => ['role' => 'assistant', 'content' => ''], 'finish_reason' => 'content_filter']],
            'usage' => ['prompt_cache_hit_tokens' => 128, 'prompt_cache_miss_tokens' => 20, 'completion_tokens' => 0],
        ]];

        yield 'sin usage ni message' => [[
            'choices' => [['finish_reason' => 'length']],
        ]];

        yield 'cifras en texto' => [[
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_cache_hit_tokens' => '100', 'prompt_cache_miss_tokens' => '5', 'completion_tokens' => '9'],
        ]];

        yield 'vacía' => [[]];
    }

    /** @param array<mixed> $datos */
    #[DataProvider('respuestasDeDeepSeek')]
    public function testDeepSeekLeeLoMismoQueElMotorDeAntes(array $datos): void
    {
        $viejo = self::lecturaViejaDeepSeek($datos);
        $leida = DeepSeekRespuesta::fromArray($datos);

        self::assertSame($viejo['acierto'], $leida->consumo->cacheLeido, 'caché acierto');
        self::assertSame($viejo['fallo'], $leida->consumo->entradaSinCache(), 'caché fallo');
        self::assertSame($viejo['salida'], $leida->consumo->salida ?? 0, 'salida');
        self::assertSame($viejo['motivoFin'], $leida->motivoFin);
        self::assertSame($viejo['hayMensaje'], $leida->hayTurno);
        self::assertSame($viejo['texto'], trim($leida->texto));
        self::assertSame($viejo['llamadas'], array_map(
            static fn (LlamadaAHerramienta $l): array => [$l->id, $l->nombre, $l->argumentos],
            $leida->llamadas,
        ));
        self::assertSame($viejo['mensaje'], $leida->hayTurno ? $leida->turnoCrudo : null, 'el turno se reenvía intacto');
    }

    /**
     * Lo que hacía `DeepSeekEngine::conversar()` con la respuesta, copiado tal cual.
     *
     * @param array<mixed> $respuesta
     * @return array{acierto: int, fallo: int, salida: int, motivoFin: string, hayMensaje: bool, texto: string, llamadas: list<array{string, string, array<mixed>}>, mensaje: mixed}
     */
    private static function lecturaViejaDeepSeek(array $respuesta): array
    {
        $uso = $respuesta['usage'] ?? [];
        $eleccion = $respuesta['choices'][0] ?? null;
        $mensaje = $eleccion['message'] ?? null;

        $llamadas = [];
        foreach ((is_array($mensaje) && is_array($mensaje['tool_calls'] ?? null) ? $mensaje['tool_calls'] : []) as $llamada) {
            $argumentos = json_decode((string) ($llamada['function']['arguments'] ?? '{}'), true);
            $llamadas[] = [(string) ($llamada['id'] ?? ''), (string) ($llamada['function']['name'] ?? ''), is_array($argumentos) ? $argumentos : []];
        }

        return [
            'acierto' => (int) ($uso['prompt_cache_hit_tokens'] ?? 0),
            'fallo' => (int) ($uso['prompt_cache_miss_tokens'] ?? 0),
            'salida' => (int) ($uso['completion_tokens'] ?? 0),
            'motivoFin' => (string) ($eleccion['finish_reason'] ?? ''),
            'hayMensaje' => is_array($mensaje),
            'texto' => is_array($mensaje) ? trim((string) ($mensaje['content'] ?? '')) : '',
            'llamadas' => $llamadas,
            'mensaje' => is_array($mensaje) ? $mensaje : null,
        ];
    }

    // ── Gemini ───────────────────────────────────────────────────────────────

    /** @return iterable<string, array{array<mixed>}> */
    public static function respuestasDeGemini(): iterable
    {
        yield 'llamada a herramienta con firma de pensamiento' => [[
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => ['name' => 'consultar_padron', 'args' => ['expediente' => '5SRAJV', 'subgrupo' => 'Fabio']],
                        'thoughtSignature' => 'CiIBVKhc7s…',
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
                'index' => 0,
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 20635,
                'candidatesTokenCount' => 33,
                'totalTokenCount' => 20668,
                'cachedContentTokenCount' => 16384,
                'thoughtsTokenCount' => 0,
                'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 20635]],
            ],
            'modelVersion' => 'gemini-3-flash-preview',
            'responseId' => 'mYb1aK…',
        ]];

        yield 'texto en dos partes, sin caché ni pensamiento' => [[
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Mañana salen '], ['text' => 'dos huéspedes.']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 9120, 'candidatesTokenCount' => 12, 'totalTokenCount' => 9132],
        ]];

        yield 'llamada sin argumentos' => [[
            'candidates' => [[
                'content' => ['parts' => [['functionCall' => ['name' => 'consultar_tipo_cambio', 'args' => []]]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 5],
        ]];

        yield 'pregunta bloqueada' => [[
            'promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT', 'safetyRatings' => []],
            'usageMetadata' => ['promptTokenCount' => 14, 'totalTokenCount' => 14],
        ]];

        yield 'respuesta cortada por los filtros' => [[
            'candidates' => [['content' => ['parts' => [], 'role' => 'model'], 'finishReason' => 'SAFETY']],
            'usageMetadata' => ['promptTokenCount' => 300, 'thoughtsTokenCount' => 52],
        ]];

        yield 'JSON truncado por MAX_TOKENS' => [[
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"tipo":"peticion","skill']], 'role' => 'model'],
                'finishReason' => 'MAX_TOKENS',
            ]],
            'usageMetadata' => ['promptTokenCount' => 912, 'candidatesTokenCount' => 12, 'thoughtsTokenCount' => 488],
        ]];

        yield 'candidato sin contenido' => [[
            'candidates' => [['finishReason' => 'STOP']],
        ]];

        yield 'vacía' => [[]];
    }

    /** @param array<mixed> $datos */
    #[DataProvider('respuestasDeGemini')]
    public function testGeminiLeeLoMismoQueElMotorDeAntes(array $datos): void
    {
        $viejo = self::lecturaViejaGemini($datos);
        $leida = GoogleRespuesta::fromArray($datos);

        self::assertSame($viejo['entrada'], $leida->consumo->entrada);
        self::assertSame($viejo['cacheado'], $leida->consumo->cacheLeido);
        self::assertSame($viejo['pensamiento'], $leida->consumo->pensamiento ?? 0);
        self::assertSame($viejo['salida'], $leida->consumo->salida ?? 0);
        self::assertSame($viejo['pensamientoTruncado'], (string) ($leida->consumo->pensamiento ?? '?'));
        self::assertSame($viejo['salidaTruncado'], (string) ($leida->consumo->salida ?? '?'));
        self::assertSame($viejo['hayCandidato'], $leida->hayTurno);
        self::assertSame($viejo['bloqueo'], $leida->motivoBloqueo);
        self::assertSame($viejo['motivoFin'], $leida->motivoFin);
        self::assertSame($viejo['texto'], $leida->texto);
        self::assertSame($viejo['llamadas'], array_map(
            static fn (LlamadaAHerramienta $l): array => [$l->nombre, $l->argumentos],
            $leida->llamadas,
        ));
        self::assertSame($viejo['partes'], $leida->turnoCrudo, 'las partes se reenvían intactas, con su thoughtSignature');
    }

    /**
     * Lo que hacían `GoogleAIEngine::conversar()` y `turnoDirecto()` con la respuesta.
     *
     * @param array<mixed> $datos
     * @return array{entrada: int, cacheado: int, pensamiento: int, salida: int, pensamientoTruncado: string, salidaTruncado: string, hayCandidato: bool, bloqueo: ?string, motivoFin: string, texto: string, llamadas: list<array{string, array<mixed>}>, partes: array<mixed>}
     */
    private static function lecturaViejaGemini(array $datos): array
    {
        $uso = is_array($datos['usageMetadata'] ?? null) ? $datos['usageMetadata'] : [];
        $candidato = $datos['candidates'][0] ?? null;
        $partes = is_array($candidato) && is_array($candidato['content']['parts'] ?? null) ? $candidato['content']['parts'] : [];

        $texto = '';
        $llamadas = [];
        foreach ($partes as $parte) {
            if (is_array($parte['functionCall'] ?? null)) {
                $llamada = $parte['functionCall'];
                $llamadas[] = [(string) ($llamada['name'] ?? ''), is_array($llamada['args'] ?? null) ? $llamada['args'] : []];
            } elseif (is_string($parte['text'] ?? null)) {
                $texto .= $parte['text'];
            }
        }

        return [
            'entrada' => (int) ($uso['promptTokenCount'] ?? 0),
            'cacheado' => (int) ($uso['cachedContentTokenCount'] ?? 0),
            'pensamiento' => (int) ($uso['thoughtsTokenCount'] ?? 0),
            'salida' => (int) ($uso['candidatesTokenCount'] ?? 0),
            'pensamientoTruncado' => (string) ($uso['thoughtsTokenCount'] ?? '?'),
            'salidaTruncado' => (string) ($uso['candidatesTokenCount'] ?? '?'),
            'hayCandidato' => is_array($candidato),
            'bloqueo' => isset($datos['promptFeedback']['blockReason']) ? (string) $datos['promptFeedback']['blockReason'] : null,
            'motivoFin' => is_array($candidato) ? (string) ($candidato['finishReason'] ?? '') : '',
            'texto' => $texto,
            'llamadas' => $llamadas,
            'partes' => $partes,
        ];
    }

    // ── Lo que sí cambió, a propósito ────────────────────────────────────────

    /** Una clave numérica no se habría leído nunca: la skill lee por nombre. */
    public function testLosArgumentosSonUnObjetoConClavesDeTexto(): void
    {
        self::assertSame([], LlamadaAHerramienta::objetoJson([1, 2]));
        self::assertSame([], LlamadaAHerramienta::objetoJson('{"a":1}'), 'una cadena no es un objeto');
        self::assertSame([], LlamadaAHerramienta::objetoJson(null));
        self::assertSame(['a' => 1, 'b' => ['c' => 2]], LlamadaAHerramienta::objetoJson(['a' => 1, 0 => 'x', 'b' => ['c' => 2]]));
    }

    /**
     * Un `arguments` que no es texto era, con el `(string)` de antes, «Array» y un aviso: acababa
     * en «sin argumentos» igual, pero ensuciando el log.
     */
    public function testUnosArgumentosQueNoSonTextoSonSinArgumentos(): void
    {
        $leida = DeepSeekRespuesta::fromArray(['choices' => [['message' => ['tool_calls' => [
            ['id' => 'c', 'function' => ['name' => 'x', 'arguments' => ['a' => 1]]],
        ]]]]]);

        self::assertSame([], $leida->llamadas[0]->argumentos);
    }

    /** DeepSeek parte la entrada en dos; el total se reconstruye y el fallo sale exacto. */
    public function testLaEntradaDeDeepSeekEsLaSumaDeSusDosMitades(): void
    {
        $consumo = DeepSeekRespuesta::fromArray(['usage' => ['prompt_cache_hit_tokens' => 5888, 'prompt_cache_miss_tokens' => 242]])->consumo;

        self::assertSame(6130, $consumo->entrada);
        self::assertSame(242, $consumo->entradaSinCache());
        self::assertNull($consumo->salida, 'no la mandó: no es 0');
    }
}
