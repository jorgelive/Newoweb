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

# ── DEUDA 1 · `version` → `propuesta` en la columna y la API ─────────────────
#
# Decidido el 02/09/2026: NO se hace por sí sola. Se renombró la cadena que ve una persona —URL,
# rutas, props, vistas, docs— y la columna se quedó con su nombre heredado, traducida en la
# frontera. El renombrado profundo cuesta 19 archivos PHP, 78 líneas de front, los dos `api.d.ts`
# regenerados y una migración sobre `int NOT NULL` en producción; el beneficio que quedaba era que
# alguien no se confunda al leer la entidad, y eso ya lo cubre un comentario.
#
# Pero si YA hay una migración sobre esa tabla, el coste marginal se desploma. Ésa es la ocasión.
case "$ARCHIVO" in
    */migrations/Version*.php)
        if grep -qi "cotizacion_cotizacion" "$ARCHIVO" 2>/dev/null; then
            aviso "💡 Estás escribiendo una migración sobre \`cotizacion_cotizacion\`, que es LA ocasión que esperaba una deuda anotada.

**\`version\` → \`propuesta\` en la columna y la API.** El 02/09/2026 se renombró todo lo que ve una persona (URL \`/p/\`, rutas, props, vistas, docs) y la columna se dejó con su nombre heredado, traducida en la frontera — porque una migración sólo para eso no compensaba.

Ahora sí hay migración. Decide **a conciencia**, no por inercia:

- **Si la migración es pequeña y el renombrado cabe sin enturbiarla** → llévalo, y regenera los dos \`api.d.ts\`.
- **Si el diff se vuelve difícil de revisar** → NO lo metas. Mezclar un renombrado mecánico con un cambio de comportamiento hace imposible revisar ninguno de los dos.

Contexto completo en \`docs/Cotizaciones.md\` §6.j.0 y \`docs/Pendientes.md\`."
            exit 0
        fi
        ;;
esac

exit 0
