<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

/**
 * Una de las personas que figuran en un E-Ticket.
 *
 * ── 🔥 Por qué esto existe: el trámite es de VARIOS ─────────────────────────
 * El E-Ticket de República Dominicana lleva una **tabla** de pasajeros, y el propio documento lo
 * dice en su letra pequeña: «un solo código QR es válido para todas las personas cuya información
 * está en el documento». Una familia rellena uno y lo sube cada uno a su ficha.
 *
 * Hasta el 16/09/2026 el lector devolvía **un nombre y un pasaporte sueltos**, así que se quedaba
 * con la primera fila de la tabla y se la atribuía a quien fuera el dueño del archivo. El resultado
 * con un formulario de dos, medido en producción:
 *
 * ```
 * ficha de  : HERBERT JESUS ZEVALLOS GUZMAN · pasaporte 125995436
 * el trámite: YUSI BETSI CRUZ ALVAREZ       · pasaporte 125995393   ← la primera fila
 * veredicto : OBSERVADO · «pasaporte dice 125995393, debería 125995436»
 *             + ocho notas de palabras que sobran y faltan
 * ```
 *
 * Todo eso era **falso**: el trámite de Herbert estaba perfecto, y estaba en la segunda fila. Y
 * falla hacia el lado peor de los dos: el primero de la lista sale en verde y **todos los demás**
 * salen acusados, que es justo al revés de lo que haría sospechar a alguien.
 *
 * ⚠️ Se guarda la lista ENTERA, no «la fila que toca». Quién es el dueño del archivo no lo sabe el
 * lector —que sólo ve píxeles— y decidirlo al leer congelaría esa decisión en el almacén: si mañana
 * cambia la regla de emparejar, habría que volver a pagar las 121 lecturas. La lista es lo que dice
 * el documento; elegir es un juicio, y los juicios viven en {@see CotejoDeEticket}.
 */
final readonly class PasajeroDelTramite
{
    public function __construct(
        public ?string $nombre = null,
        /** El número de pasaporte tal como lo declaró el pasajero AL RELLENAR el trámite. */
        public ?string $pasaporte = null,
        public ?string $nacionalidad = null,
    ) {}

    public function estaVacio(): bool
    {
        return $this->nombre === null && $this->pasaporte === null;
    }
}
