#!/usr/bin/env bash
#
# Prohíbe estadiar a ciegas — ver la sección "Despliegue" de CLAUDE.md.
#
# El 28/08/2026 un `git add -A` barrió trabajo sin commitear que había en el árbol
# —enlaces de pago: entidad, servicio, dos vistas Vue y una MIGRACIÓN— y lo metió
# dentro de cuatro commits que hablaban de otra cosa. La migración se desplegó sin
# ejecutarse y la guía del huésped dio 500.
#
# El árbol es compartido: el editor de una persona y una sesión de agente escriben en
# la misma carpeta. `-A` y `.` no distinguen quién escribió qué, así que la única
# defensa es nombrar las rutas. `git commit -a` cae por lo mismo: estadia toda
# modificación de archivo ya seguido.
#
# Lo que SÍ se puede: `git add ruta/al/archivo`, `git add -p`, `git add -u ruta/`.
#
# Este hook NO opina sobre el contenido; sólo obliga a que el comando diga qué entra.
set -uo pipefail

PAYLOAD="$(cat)"
COMANDO="$(printf '%s' "$PAYLOAD" | jq -r '.tool_input.command // empty' 2>/dev/null)"

[ -z "$COMANDO" ] && exit 0

# Se examina cada tramo por separado: un `cd x && git add -A` es dos órdenes, y la
# peligrosa es la segunda.
MOTIVO=""

while IFS= read -r tramo; do
    # Normaliza espacios para que `git   add` no se escape.
    t="$(printf '%s' "$tramo" | tr '\n' ' ' | sed 's/  */ /g; s/^ //; s/ $//')"

    case "$t" in
        *"git add"*)
            # ¿Aparece -A, --all, o un punto suelto como ruta?
            if printf '%s' "$t" | grep -qE '(^|[[:space:]])(-A|--all)([[:space:]]|$)' \
               || printf '%s' "$t" | grep -qE 'git add([[:space:]]+-[^[:space:]]+)*[[:space:]]+\.([[:space:]]|$)'; then
                MOTIVO="git add -A / git add ."
            fi
            ;;
    esac

    case "$t" in
        *"git commit"*)
            # -a suelto o dentro de un grupo corto (-am, -av…), pero NO --amend ni --author.
            if printf '%s' "$t" | grep -qE '(^|[[:space:]])-[a-zA-Z]*a[a-zA-Z]*([[:space:]]|$)' \
               || printf '%s' "$t" | grep -qE '(^|[[:space:]])--all([[:space:]]|$)'; then
                MOTIVO="git commit -a"
            fi
            ;;
    esac
done <<< "$(printf '%s' "$COMANDO" | sed 's/&&/\n/g; s/||/\n/g; s/;/\n/g')"

[ -z "$MOTIVO" ] && exit 0

jq -n --arg motivo "$MOTIVO" '{
    hookSpecificOutput: {
        hookEventName: "PreToolUse",
        permissionDecision: "deny",
        permissionDecisionReason: (
            "Bloqueado: «" + $motivo + "» estadia TODO lo que hay en el árbol de trabajo.\n\n"
            + "Este directorio es compartido: puede haber trabajo sin commitear que no es tuyo. "
            + "El 28/08/2026 un `git add -A` se llevó una entidad, un servicio, dos vistas Vue y una "
            + "MIGRACIÓN de otra tarea, dentro de commits que hablaban de otra cosa; la migración se "
            + "desplegó sin ejecutar y la guía del huésped dio 500 a todos los huéspedes.\n\n"
            + "Qué hacer:\n"
            + "1) `git status --porcelain` y mira qué hay ahí.\n"
            + "2) Estadía por RUTA lo que tú tocaste: `git add src/Foo.php docs/Foo.md`.\n"
            + "3) Si no reconoces un archivo, NO lo incluyas: pregunta.\n\n"
            + "Permitidos: `git add <ruta>`, `git add -p`, `git add -u <ruta>`."
        )
    }
}'
exit 0
