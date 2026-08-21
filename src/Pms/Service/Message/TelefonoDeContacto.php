<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Service\Conversacion\ContactoDelAsunto;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;

/**
 * El teléfono al que se le escribe a esta reserva.
 *
 * ── Por qué ya no vale leer `PmsReserva::$telefono` ─────────────────────────
 * Ese campo es una **semilla**: lo que el canal o el operador escribieron al dar de alta la
 * reserva, y lo que sirve para crear la identidad la primera vez. A partir de ahí deja de ser
 * la verdad — el número correcto vive en las identidades de la persona, que es donde se puede
 * corregir, retirar y marcar cuál es el bueno.
 *
 * Y son datos que se contradicen a la primera: el huésped da un número mal, se corrige en el
 * editor, y la reserva se queda con el viejo para siempre. Repetido en cada reserva de la misma
 * persona, el conflicto se multiplica.
 *
 * ── Ya no resuelve: DELEGA ──────────────────────────────────────────────────
 * La regla dejó de ser del PMS el 21/08/2026, cuando el expediente de Turismo y las
 * organizaciones proveedoras pasaron a necesitar exactamente la misma —y el correo además del
 * teléfono—. Vive en {@see \App\Message\Service\Conversacion\ContactoDelAsunto}, que la
 * responde para cualquier dominio a partir de dos cosas que ya existían: el hilo del asunto y
 * el contexto que publica sus semillas.
 *
 * Esta clase se queda como la puerta del PMS —tipada a `PmsReserva`, que es lo que tienen sus
 * llamadores— y **no repite ni un `if`**. Copiarla para el tercer dominio habría dado tres
 * versiones que envejecen por separado, y la tercera es siempre la que se olvida de mirar si la
 * identidad está vetada.
 */
final readonly class TelefonoDeContacto
{
    public function __construct(private ContactoDelAsunto $contacto)
    {
    }

    public function para(?PmsReserva $reserva): ?string
    {
        return $this->resuelto($reserva)['telefono'] ?? null;
    }

    /** ¿El número sale de una IDENTIDAD verificable, o del campo de la reserva? */
    public function vieneDeIdentidad(?PmsReserva $reserva): bool
    {
        return ($this->resuelto($reserva)['telefonoOrigen'] ?? null) === 'identidad';
    }

    /** @return array{telefono?: ?string, telefonoOrigen?: ?string} */
    private function resuelto(?PmsReserva $reserva): array
    {
        if ($reserva === null) {
            return [];
        }

        return $this->contacto->para(PmsConversacionEnlace::CONTEXT_TYPE, (string) $reserva->getId());
    }
}
