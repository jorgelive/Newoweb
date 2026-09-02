#!/usr/bin/env bash
#
# Candado de tests — que la red no dependa de que alguien se acuerde.
#
# Hasta el 02/09/2026 había 540 tests en PHP y ninguno en TypeScript, y **nada** obligaba a
# correr ninguno de los dos. Existía un hook que corta el turno si se toca `src/` sin documentar,
# y ni uno solo que impidiera cerrar con los tests en rojo: la documentación estaba mejor
# protegida que el código. Esto cierra esa asimetría.
#
# Mismo mecanismo de dos tiempos que `docs-guard.sh`, porque un Stop hook no sabe qué archivos se
# tocaron:
#   track  (PostToolUse/Write|Edit) → apunta cada archivo escrito en la sesión.
#   check  (Stop)                   → corre SÓLO las suites cuyo territorio se tocó.
#
# ⚠️ **Corre sólo lo que toca, a propósito.** Un candado que tarda en cada turno se acaba
# desactivando, y un candado desactivado es peor que ninguno porque da sensación de red. Si no se
# tocó nada vigilado, esto sale en milisegundos sin ejecutar nada.
#
set -uo pipefail

MODE="${1:-check}"
PAYLOAD="$(cat)"
SESSION="$(printf '%s' "$PAYLOAD" | jq -r '.session_id // "nosession"' 2>/dev/null || echo nosession)"
LISTA="${TMPDIR:-/tmp}/claude-tests-guard-${SESSION}.txt"
RAIZ="${CLAUDE_PROJECT_DIR:-$(pwd)}"

case "$MODE" in
    track)
        printf '%s' "$PAYLOAD" \
            | jq -r '.tool_input.file_path // .tool_response.filePath // empty' 2>/dev/null \
            >> "$LISTA"
        exit 0
        ;;
esac

[ -f "$LISTA" ] || exit 0

# Ya venimos de un bloqueo de este mismo hook: no reincidir, o se forma un bucle.
if [ "$(printf '%s' "$PAYLOAD" | jq -r '.stop_hook_active // false' 2>/dev/null)" = "true" ]; then
    rm -f "$LISTA"
    exit 0
fi

TOCADOS="$(sort -u "$LISTA" 2>/dev/null || true)"
rm -f "$LISTA"

FALLOS=""

# ── Las suites, y qué territorio vigila cada una ─────────────────────────────
# Añadir una es una línea. Cuando `util` tenga sus tests de dominio, va aquí.
#
# ⚠️ Las rutas son PREFIJOS ABSOLUTOS y eso importa: `src/` a secas también casaría con
# `util/src/` y `pax/src/`, y entonces cualquier retoque de una vista dispararía PHPUnit.
comprobar() {
    local territorio="$1" etiqueta="$2"; shift 2

    printf '%s\n' "$TOCADOS" | grep -q "^${territorio}" || return 0

    local salida
    if ! salida="$("$@" 2>&1)"; then
        FALLOS="${FALLOS}
── ${etiqueta} ──
$(printf '%s' "$salida" | tail -25)
"
    fi
}

# El dominio compartido. Corre sus propios tests: no depende de ninguna app.
if [ -d "$RAIZ/dominio/node_modules/.bin" ]; then
    comprobar "$RAIZ/dominio/" "dominio · npm test" \
        bash -c "cd '$RAIZ/dominio' && npm test --silent"
else
    printf '%s\n' "$TOCADOS" | grep -q "^$RAIZ/dominio/" && FALLOS="${FALLOS}
── dominio ──
Se tocó dominio/ pero no hay node_modules: los tests NO se han corrido.
Ejecuta 'cd dominio && npm ci' antes de dar esto por bueno.
"
fi

# El editor. Su territorio es más ancho que el de `pax` a propósito: aquí las reglas todavía viven
# DENTRO de los stores, no en módulos aparte, así que vigilar sólo una carpeta no serviría.
if [ -d "$RAIZ/util/node_modules/.bin" ]; then
    comprobar "$RAIZ/util/src/stores/" "util · npm test" \
        bash -c "cd '$RAIZ/util' && npm test --silent"
fi

# Backend. `bin/phpunit` tarda medio segundo con 540 tests, así que no molesta.
comprobar "$RAIZ/src/" "PHP · phpunit" \
    bash -c "cd '$RAIZ' && php bin/phpunit"

[ -z "$FALLOS" ] && exit 0

jq -n --arg detalle "$FALLOS" '{
    decision: "block",
    reason: (
        "Los tests no pasan. NO cierres el turno con esto en rojo.\n"
        + $detalle
        + "\nDos caminos, y sólo dos:\n"
        + "1) El código está mal -> arréglalo.\n"
        + "2) El comportamiento cambió A PROPÓSITO -> di explícitamente QUÉ cambió y por qué es\n"
        + "   mejor, y sólo entonces actualiza el test o el snapshot.\n\n"
        + "⚠️ Un snapshot NO se actualiza con -u porque salió en rojo: eso vacía el propósito de\n"
        + "tenerlo. Se lee el diff y se decide."
    )
}'
exit 0
