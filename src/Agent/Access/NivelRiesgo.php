<?php

declare(strict_types=1);

namespace App\Agent\Access;

/**
 * Cuánto daño puede hacer una herramienta. El control se escala con el daño: una consulta se
 * ejecuta sin más, una escritura se le enseña a quien la pidió antes de aplicarla.
 *
 * ⚠️ **NO hay un nivel «destructivo», y su ausencia es deliberada.** Lo hubo, con un
 * `requierePin()` que no llamaba nadie y un PIN que no existía en ninguna parte del sistema:
 * una etiqueta que prometía un control inexistente. Se quitó entero para que la primera
 * herramienta que de verdad destruya datos **no compile** hasta que alguien añada el nivel —
 * y ese día diseñe el control, en vez de heredar una promesa vacía. Encaja además con la
 * regla de la casa: aquí no se borra, se marca.
 *
 * La política se aplica UNA vez en el asistente, no en cada herramienta — así una
 * herramienta nueva hereda el trato que le corresponde con sólo declarar su nivel.
 */
enum NivelRiesgo: string
{
    /** Sólo lee. Se ejecuta sin preguntar. */
    case Lectura = 'lectura';

    /**
     * Escribe, pero sólo HACIA DENTRO: avisos al equipo, marcas de pendiente, apuntes en el
     * panel. No toca la reserva, ni la cuenta, ni nada que el huésped vea como suyo.
     *
     * Existe porque el chat del huésped se abre en modo sólo-lectura ({@see
     * \App\Agent\Skill\SkillRegistry::paraActor()} con `$incluirEscritura = false`) y eso
     * dejaba fuera justo la herramienta que más falta le hace: la de pedir ayuda. El huésped
     * es quien tiene el problema y quien tiene que poder levantar la mano; que no pudiera
     * era el fallo. Ver {@see \App\Agent\Skill\Pms\EscalarAlEquipoSkill}.
     *
     * El asimétrico está pensado: el daño de un aviso de más es que un operador mire un chat
     * que no hacía falta. El de una escritura de más es un dato falso en la reserva.
     */
    case Interna = 'interna';

    /**
     * Modifica datos. El asistente enseña el cambio a QUIEN LO PIDIÓ y espera su «sí».
     *
     * ⚠️ Este nivel es **sólo para operadores**: toda skill que lo declara pide
     * `RESERVAS_WRITE` o `MENSAJES_WRITE`, así que un huésped no llega a ninguna. Lo único
     * no-lectura a su alcance es `Interna`, y son acciones sobre sí mismo.
     *
     * ── Qué es «confirmar», porque se lee mal ───────────────────────────────────
     * ⚠️ En este sistema «confirmar» nombra DOS cosas distintas. Ésta es la primera:
     *
     *   A. **Confirmar una escritura** (lo de aquí): el operador ve sus propios cambios y los
     *      acepta. Mismo usuario, dos turnos.
     *   B. **Confirmarle algo a un cliente**: un huésped pide un late check-out o una tarifa
     *      especial. Eso el agente NO lo concede por su cuenta — escala a la oficina. No es
     *      una escritura sin confirmar, es una decisión que no le toca al modelo. Ver
     *      {@see \App\Agent\Skill\Pms\EscalarAlEquipoSkill}.
     *
     * La A es una conversación de dos turnos con **la misma persona**:
     *
     * ```
     * turno 1  usuario  «cambia la clave de la caja principal a 2627E»
     *          skill    confirmado=false → NO TOCA NADA, devuelve la previsualización
     *          modelo   «la usan 3 huéspedes ahora mismo (Ana, Luis, Marta). ¿La cambio?»
     * turno 2  usuario  «sí»
     *          skill    confirmado=true  → aplica
     * ```
     *
     * **No es pedirle permiso a un tercero.** No hay aprobador, ni supervisor, ni segundo par
     * de ojos: quien pide y quien confirma son el mismo. Lo único que se añade es que vea el
     * efecto antes de que ocurra.
     *
     * Y de ahí sale lo que protege: no duda de que el usuario quiera hacerlo —acaba de
     * decirlo—, duda de que **el modelo le haya entendido**. Con dos Carlos González, mover la
     * reserva del que no era es una alucinación, no un ataque; la previsualización es el
     * instante en que un humano lo caza. Por eso su contenido sale de los datos reales y no de
     * lo que el modelo cree que va a pasar.
     *
     * Corolario que ya costó una confusión: **en un chat siempre hay a quién preguntar** — es
     * quien acaba de escribir. Si un canal cierra la escritura, el motivo será otro (ver
     * `AiConversationProcessor`), nunca que falte el interlocutor.
     */
    case Escritura = 'escritura';

    /**
     * ¿Hace falta permiso de escritura para ofrecer esta herramienta?
     *
     * Es la pregunta del filtro del registro, y NO es «¿escribe algo?»: `Interna` escribe y
     * aun así pasa. Un método propio y no una comparación suelta porque el día que aparezca
     * otro nivel, el criterio se cambia aquí y no en cada sitio que filtre.
     */
    public function exigePermisoDeEscritura(): bool
    {
        return $this === self::Escritura;
    }

    /**
     * ¿Dejó algo distinto en la base, de modo que lo que hay en pantalla ya no vale?
     *
     * La usa el asistente del panel para avisar a la vista que lo embebe de que sus datos
     * quedaron viejos: el operador pide «registra la salida a las 8», la skill lo guarda, y
     * la lista de salidas de arriba seguía mostrando las 10:00 hasta recargar a mano.
     *
     * ⚠️ NO es `exigePermisoDeEscritura()`. `Interna` no pide permiso ni confirmación, pero sí
     * escribe —marca la conversación como no leída, por ejemplo— y esa marca también es algo
     * que la pantalla está mostrando desactualizado. Aquí la pregunta es «¿cambió algo?», no
     * «¿hacía falta permiso?».
     */
    public function modificaDatos(): bool
    {
        return $this !== self::Lectura;
    }
}
