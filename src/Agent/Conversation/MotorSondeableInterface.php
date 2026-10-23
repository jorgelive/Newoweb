<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

/**
 * Un motor al que se le puede preguntar «¿respondes?» sin un huésped delante (17/10/2026).
 *
 * Lo usa `app:agent:vigilar-motor`. Tiene que ser una GENERACIÓN de verdad, mínima: listar modelos
 * o comprobar la clave no descubre unos créditos agotados, que es justo lo que pasó el 16/10/2026.
 * Ver `DecisionDelSondeo`.
 */
interface MotorSondeableInterface
{
    /**
     * `null` si respondió; si no, el fallo con su código HTTP y si es definitivo, que lo decide el
     * cliente de cada proveedor y no quien lo lee (ver {@see FalloDelMotor}).
     */
    public function sondear(): ?FalloDelMotor;
}
