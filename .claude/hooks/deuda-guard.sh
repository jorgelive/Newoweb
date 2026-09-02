#!/usr/bin/env bash
#
# Recordatorios que esperan a su momento.
#
# Hay deuda que no se salda cuando se descubre, sino cuando aparece una ocasión que la abarata: un
# renombrado de columna que sólo compensa si ya hay una migración de por medio, un índice que sólo
# vale la pena con la tabla parada. Anotarla en un `.md` no sirve — el día que llega la ocasión,
# nadie está leyendo ese archivo.
#
# Esto la trae de vuelta **en el momento en que se puede pagar**: al escribir el archivo que abre
# la ventana. No bloquea nada; sólo lo dice.
#
# ⚠️ **Cubre el trabajo hecho por el agente, no el que se hace a mano en el IDE.** Es una red
# parcial y conviene saberlo: la entrada equivalente vive también en `docs/Pendientes.md` para
# quien escriba una migración desde PhpStorm.
#
set -uo pipefail

PAYLOAD="$(cat)"
ARCHIVO="$(printf '%s' "$PAYLOAD" | jq -r '.tool_input.file_path // .tool_response.filePath // empty' 2>/dev/null)"

[ -n "$ARCHIVO" ] || exit 0
[ -f "$ARCHIVO" ] || exit 0

aviso() {
    jq -n --arg texto "$1" '{
        hookSpecificOutput: {
            hookEventName: "PostToolUse",
            additionalContext: $texto
        }
    }'
}

# ── (sin deudas esperando ahora mismo) ──────────────────────────────────────
#
# La primera fue `version` → `propuesta` en la columna y la API. Se pagó el 02/09/2026 sin esperar
# a su ocasión: al ir a implementar la propuesta operativa se comprobó que ese trabajo NO toca
# `cotizacion_cotizacion`, así que la ocasión no iba a llegar — el recordatorio habría esperado
# para siempre dando falsa tranquilidad, mientras cada función nueva sumaba un sitio más leyendo
# el nombre equivocado.
#
# La lección, para la próxima entrada: **antes de poner a esperar una deuda, comprueba que su
# disparador puede dispararse.** Un recordatorio que nunca salta es peor que ninguno.
#
# Para añadir una: un `case` sobre la ruta del archivo que abre la ventana, y `aviso "..."` con el
# contexto entero y el criterio de cuándo NO hacerlo.

exit 0
