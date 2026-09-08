<?php

declare(strict_types=1);

namespace App\Panel\Contract;

/**
 * Esta imagen **se lee**, no se mira.
 *
 * ── Por qué no basta con una interfaz marcadora ─────────────────────────────
 * A diferencia de {@see RequiresJpegConversionInterface}, esto **no lo decide la clase**: la misma
 * entidad guarda un boleto, una factura y el escaneo de un pasaporte. Lo decide el ejemplar, por
 * lo que sea que lleve dentro — de ahí el método en vez del marcador.
 *
 * ── Qué cambia ──────────────────────────────────────────────────────────────
 * `VichWebpConversionListener` usa el filtro `documento_identidad` (2400 px, calidad 88) en vez del
 * normal (1600 px, calidad 80). Pesa el triple y es lo que se quiere: a 1600 px, un DNI
 * fotografiado sobre una mesa deja el número en ~800 px de ancho, y la letra pequeña desaparece.
 *
 * 🔥 **Y no hay segunda oportunidad**: el escaneo no se le devuelve al pasajero, así que un
 * documento ilegible no se corrige mirándolo otra vez — se le vuelve a pedir.
 */
interface RequiereAltaFidelidadInterface
{
    /** ¿Este ejemplar concreto hay que poder LEERLO? */
    public function requiereAltaFidelidad(): bool;
}
