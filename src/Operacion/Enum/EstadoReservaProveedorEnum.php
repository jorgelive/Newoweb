<?php

declare(strict_types=1);

namespace App\Operacion\Enum;

/**
 * ¿El PROVEEDOR ya me confirmó este servicio?
 *
 * ── Por qué el nombre lleva «Proveedor» ─────────────────────────────────────
 * Se llamaba `EstadoReservaEnum`, sobre un campo `estadoReserva`, y la palabra sobraba por
 * los dos lados: en este sistema «reserva» es {@see \App\Pms\Entity\PmsReserva} —la del
 * huésped—, mientras que esto es la reserva que YO le hago al proveedor. Quien leyera
 * «estadoReserva» en una fila de La Biblia tenía todo el derecho a entender lo contrario de
 * lo que dice.
 *
 * Se renombró antes de que lo leyera nadie más: el agente conversacional va a contestar con
 * esto cuando un pasajero pregunte «¿ya está confirmado mi tour?», y ahí equivocarse de
 * estado no da error —da una respuesta tranquilizadora y falsa—.
 *
 * ── No confundir con los otros dos estados de la fila ───────────────────────
 * Son tres y responden a preguntas distintas (ver docs/Operacion.md §«Los tres estados»):
 *
 *   - éste                    → ¿el proveedor ya me confirmó?      (la plaza existe)
 *   - {@see EstadoOperacionEnum}    → ¿el servicio ya ocurrió?
 *   - {@see EstadoOrdenServicioEnum} → ¿en qué punto está el papeleo de la orden de compra?
 *
 * Al pasajero se le contesta con ÉSTE. El de la orden es papeleo interno con el proveedor y
 * no significa nada para quien viaja.
 */
enum EstadoReservaProveedorEnum: string
{
    case SIN_SOLICITAR  = 'sin-solicitar';
    case SOLICITADO     = 'solicitado';
    case CONFIRMADO     = 'confirmado';
    case RECONFIRMADO   = 'reconfirmado';
    case PENDIENTE_PAGO = 'pendiente-pago';
}
