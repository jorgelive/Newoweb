<?php

declare(strict_types=1);

namespace App\Message\Contract;

use DateTimeInterface;

/**
 * Un momento con nombre dentro de una estancia: la llegada, una salida temporal, un reingreso,
 * un cambio de casita, la salida final.
 *
 * ── Por qué no basta con `start` y `end` ────────────────────────────────────
 * Los hitos de hoy ({@see ConversationMilestoneInterface}) son un mapa plano de clave a fecha, y
 * la estancia entera se resume en dos: `start` = `PmsReserva::getFechaLlegada()` y `end` =
 * `getFechaSalida()`. Los dos son **agregados mín/máx de todos los tramos**, así que una
 * estancia partida se aplasta en una sola ventana:
 *
 * ```
 *   Casita 1   10 → 12          se va a Machu Picchu
 *   Casita 3   15 → 18          vuelve, y además a otra casita
 *
 *   lo que ve la mensajería hoy:   start = 10,  end = 18
 * ```
 *
 * El huésped recibe la bienvenida el 10 y las instrucciones de salida el 18. **El 12 se va sin
 * que nadie le diga cómo dejar la casita, y el 15 vuelve sin que nadie le diga que ahora es la
 * 3 ni cuál es su código.** Medido en la base: de 25 reservas de varios tramos, 20 tienen hueco
 * y 8 cambian de unidad. No es un caso de laboratorio.
 *
 * ── Por qué una LISTA y no más claves en el mapa ────────────────────────────
 * Porque una estancia puede tener varios huecos —dos escapadas en un viaje largo— y un mapa
 * `clave => fecha` sólo admite uno de cada. Con una lista, el motor de reglas puede programar
 * un mensaje por cada ocurrencia en vez de por cada tipo.
 */
final readonly class HitoDeEstancia
{
    public function __construct(
        /** Una de las constantes de {@see ConversationMilestoneInterface}. */
        public string $tipo,
        public DateTimeInterface $fecha,
        /** La casita a la que se refiere el hito, cuando aplica: a la que entra o de la que sale. */
        public ?string $unidad = null,
        /** De dónde viene, sólo en un cambio de unidad. Sirve para redactar «de la 1 a la 3». */
        public ?string $unidadAnterior = null,
    ) {}
}
