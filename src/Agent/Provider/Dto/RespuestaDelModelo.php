<?php

declare(strict_types=1);

namespace App\Agent\Provider\Dto;

/**
 * Una vuelta del modelo, leída: lo que dijo, lo que pidió ejecutar, por qué paró y lo que costó.
 *
 * ── Un tipo, una lectura por proveedor ──────────────────────────────────────
 * Cada API devuelve su propio JSON (`choices[0].message` en DeepSeek, `candidates[0].content.parts`
 * en Gemini) y cada uno se lee en SU carpeta —{@see \App\Agent\Provider\DeepSeek\DeepSeekRespuesta},
 * {@see \App\Agent\Provider\Google\GoogleRespuesta}—, que es el único sitio que toca el array crudo.
 * Los motores trabajan con esto. Ver `docs/TiposDeFrontera.md`.
 *
 * Anthropic no pasa por aquí, y no es un olvido: su SDK ya devuelve objetos tipados
 * (`BetaMessage`, `usage->cacheReadInputTokens`) y el bucle lo lleva su `toolRunner`, así que no
 * hay JSON crudo que leer. Traducirlo a esto sería una capa sin frontera detrás.
 *
 * ── Lo único que va sin leer ────────────────────────────────────────────────
 * {@see self::$turnoCrudo}: el turno del modelo tal cual llegó, porque hay que DEVOLVÉRSELO en la
 * vuelta siguiente y la API lo quiere intacto (DeepSeek empareja cada `tool_call_id` contra él;
 * Gemini rechaza un `functionResponse` sin su `functionCall`). No se interpreta: se reenvía.
 */
final readonly class RespuestaDelModelo
{
    /**
     * @param list<LlamadaAHerramienta> $llamadas
     * @param array<mixed> $turnoCrudo
     */
    public function __construct(
        /**
         * Si vino algo que leer. DeepSeek: un `message`; Gemini: un candidato. Sin él no hay texto
         * ni llamadas, y el motor distingue «vacío» de «filtrado» con los motivos de abajo.
         */
        public bool $hayTurno,
        /** El texto, con las partes concatenadas. Sin recortar: eso lo decide cada motor. */
        public string $texto,
        public array $llamadas,
        /** `finish_reason` / `finishReason`; `''` si no vino. */
        public string $motivoFin,
        /** Gemini: los filtros tumbaron la PREGUNTA (`promptFeedback.blockReason`). */
        public ?string $motivoBloqueo,
        public ConsumoDeTokens $consumo,
        public array $turnoCrudo,
    ) {}
}
