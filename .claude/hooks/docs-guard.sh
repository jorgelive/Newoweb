#!/usr/bin/env bash
#
# Recordatorio de documentación — ver la sección "Documentación" de CLAUDE.md.
#
# Funciona en dos tiempos porque un Stop hook no sabe qué archivos se tocaron:
#   track  (PostToolUse/Write|Edit) → apunta cada archivo escrito en la sesión.
#   check  (Stop)                   → si hubo código sin docs, corta y avisa.
#
# El registro se borra ANTES de emitir el aviso: así el siguiente Stop no
# encuentra lista y no se puede formar un bucle de bloqueos.
#
set -uo pipefail

MODE="${1:-check}"
PAYLOAD="$(cat)"
SESSION="$(printf '%s' "$PAYLOAD" | jq -r '.session_id // "nosession"' 2>/dev/null || echo nosession)"
LISTA="${TMPDIR:-/tmp}/claude-docs-guard-${SESSION}.txt"

# Rutas cuyo cambio exige revisar documentación.
# `/src/` cubre las tres bases de código: src/ (Symfony), util/src/ y pax/src/ (Vue).
CODIGO_RE='/src/'
# Rutas que cuentan como "ya documenté".
DOCS_RE='(/docs/|/CLAUDE\.md$)'

case "$MODE" in
    track)
        printf '%s' "$PAYLOAD" \
            | jq -r '.tool_input.file_path // .tool_response.filePath // empty' 2>/dev/null \
            >> "$LISTA"
        ;;

    check)
        [ -f "$LISTA" ] || exit 0

        # Ya venimos de un bloqueo de este mismo hook: no reincidir.
        if [ "$(printf '%s' "$PAYLOAD" | jq -r '.stop_hook_active // false' 2>/dev/null)" = "true" ]; then
            rm -f "$LISTA"
            exit 0
        fi

        n_codigo="$(grep -cE "$CODIGO_RE" "$LISTA" 2>/dev/null || true)"
        n_docs="$(grep -cE "$DOCS_RE" "$LISTA" 2>/dev/null || true)"

        if [ "${n_codigo:-0}" -gt 0 ] && [ "${n_docs:-0}" -eq 0 ]; then
            tocados="$(grep -E "$CODIGO_RE" "$LISTA" | sort -u | head -12)"
            rm -f "$LISTA"

            jq -n --arg archivos "$tocados" '{
                decision: "block",
                reason: (
                    "Modificaste código sin tocar documentación en esta sesión:\n\n"
                    + $archivos
                    + "\n\nRevisa la sección \"Documentación\" de CLAUDE.md y decide:\n"
                    + "1) Si el cambio introduce o altera una regla de negocio, un flujo entre módulos, "
                    + "una decisión de arquitectura o un gotcha -> actualiza el doc que corresponda en docs/ "
                    + "(y el mapa módulo->doc de CLAUDE.md si hace falta).\n"
                    + "2) Si es refactor cosmético, un fix trivial o algo ya cubierto por los docs -> "
                    + "dilo explícitamente en una línea y termina.\n\n"
                    + "No inventes documentación de relleno: la regla es documentar lo que NO se ve leyendo el código."
                )
            }'
            exit 0
        fi

        rm -f "$LISTA"
        ;;
esac

exit 0
