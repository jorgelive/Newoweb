<?php

declare(strict_types=1);

namespace App\Agent\Tool;

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
     * Modifica datos. El asistente propone el cambio y espera un «sí».
     *
     * No es por desconfiar del usuario: es que el modelo puede equivocarse de persona. Con
     * dos Carlos González, mover la reserva del que no era es una alucinación, no un ataque.
     */
    case Escritura = 'escritura';

    /** Destruye datos. Propone, y además exige PIN antes de ejecutar. */
    case Destructivo = 'destructivo';

    public function requiereConfirmacion(): bool
    {
        return $this !== self::Lectura;
    }

    public function requierePin(): bool
    {
        return $this === self::Destructivo;
    }
}
