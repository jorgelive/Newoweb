<?php

declare(strict_types=1);

namespace App\Agent\Access;

/**
 * Cuánto daño puede hacer una herramienta. El control se escala con el daño: una consulta
 * no pide nada, un borrado pide PIN.
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
     * Modifica datos. El asistente propone el cambio y espera un «sí».
     *
     * No es por desconfiar del usuario: es que el modelo puede equivocarse de persona. Con
     * dos Carlos González, mover la reserva del que no era es una alucinación, no un ataque.
     */
    case Escritura = 'escritura';

    /** Destruye datos. Propone, y además exige PIN antes de ejecutar. */
    case Destructivo = 'destructivo';

    /**
     * ¿Hace falta permiso de escritura para ofrecer esta herramienta?
     *
     * Es la pregunta del filtro del registro, y NO es «¿escribe algo?»: `Interna` escribe y
     * aun así pasa. Un método propio y no una comparación suelta porque el día que aparezca
     * otro nivel, el criterio se cambia aquí y no en cada sitio que filtre.
     */
    public function exigePermisoDeEscritura(): bool
    {
        return $this === self::Escritura || $this === self::Destructivo;
    }

    public function requiereConfirmacion(): bool
    {
        // `Interna` tampoco la pide: quien la dispara suele ser el huésped, que no tiene a
        // quién confirmársela —y preguntarle «¿aviso al equipo?» a alguien que acaba de pedir
        // ayuda es un paso de más en el peor momento.
        return $this->exigePermisoDeEscritura();
    }

    public function requierePin(): bool
    {
        return $this === self::Destructivo;
    }
}
