<?php

declare(strict_types=1);

namespace App\Pms\Dispatch;

/**
 * Pasadas 2 horas desde que un huésped pulsó «Necesito salir tarde» / «Necesito llegar antes»:
 * si no dijo la hora ni le contestó nadie, se avisa al equipo UNA vez. Lo encola
 * `PedirHoraActionHandler` con retraso; ver `AvisarHoraSinRespuestaDispatchHandler`.
 */
final readonly class AvisarHoraSinRespuestaDispatch
{
    public const int ESPERA_SEGUNDOS = 2 * 3600;

    public function __construct(
        /** El mensaje del botón: lo que pasó DESPUÉS de él es lo que se mira. */
        public string $mensajeBotonId,
        public bool $esSalida,
    ) {}
}
