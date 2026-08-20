<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
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
 * ── El respaldo, y por qué existe ───────────────────────────────────────────
 * Si el asunto todavía no tiene hilo —una reserva recién creada, antes de que corra el
 * recálculo— no hay identidad de la que tirar. Ahí se devuelve la semilla, que es exactamente
 * lo que se usaba antes: el peor caso es el comportamiento de siempre.
 */
final readonly class TelefonoDeContacto
{
    public function __construct(private EnlacesDeConversacion $enlaces)
    {
    }

    public function para(?PmsReserva $reserva): ?string
    {
        if ($reserva === null) {
            return null;
        }

        $identidad = $this->identidadDe($reserva);

        if ($identidad !== null) {
            return $identidad->getValor();
        }

        $semilla = trim((string) $reserva->getTelefono());

        return $semilla !== '' ? $semilla : null;
    }

    /**
     * ¿El número sale de una IDENTIDAD verificable, o del campo de la reserva?
     *
     * No se puede deducir comparando valores: lo normal es que coincidan —la identidad se
     * sembró desde ahí—, así que compararlos diría «semilla» justo cuando sí hay identidad. Lo
     * tiene que contestar quien lo resolvió.
     */
    public function vieneDeIdentidad(?PmsReserva $reserva): bool
    {
        return $reserva !== null && $this->identidadDe($reserva) !== null;
    }

    /** La identidad principal de la persona de esta reserva, si su asunto ya tiene hilo. */
    private function identidadDe(PmsReserva $reserva): ?MessageIdentidad
    {
        $hilo = $this->enlaces->hiloTitularDe(
            PmsConversacionEnlace::CONTEXT_TYPE,
            (string) $reserva->getId()
        );

        $principal = $hilo?->getTelefonoPrincipal();

        // Vetado o retirado no es «el teléfono al que se escribe»: es el que NO hay que usar.
        // Se prefiere caer a la semilla —que al menos es un dato— a ofrecer un número muerto.
        return $principal !== null
            && $principal->getTipo() === IdentidadTipo::TELEFONO
            && !$principal->isBloqueado()
                ? $principal
                : null;
    }
}
