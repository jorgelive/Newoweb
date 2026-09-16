<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Un país que exige un trámite migratorio propio, y **por qué aeropuertos se entra y se sale de él**.
 *
 * ── Para qué existe ─────────────────────────────────────────────────────────
 * Es la mitad que faltaba del control del E-Ticket. Los vuelos están y los pasaportes están, pero
 * nada decía **contra cuál** cotejar el trámite: un expediente tiene tres formas de entrar a Punta
 * Cana y dos de salir, y elegir la equivocada da un veredicto peor que no dar ninguno.
 *
 * ```
 *   DM6771  LIM→PUJ  18/09          CM749  PUJ→PTY  22/09
 *   CM177   PTY→PUJ  18/09          DM6770 PUJ→LIM  22/09
 *   DM6775  LIM→PUJ  21/09
 * ```
 *
 * 🔑 **Por pasajero no hay ambigüedad**: sus vuelos son los de SU subgrupo aéreo, y ahí queda uno
 * de cada. Ver {@see \App\Cotizacion\Documento\CruceDeFrontera}.
 *
 * ── Por qué una lista de aeropuertos y no un maestro ────────────────────────
 * ⚠️ No hay maestro de aeropuertos en el sistema —`CotizacionVuelo::$origen` y `$destino` son tres
 * letras IATA a pelo— y montarlo para esto sería traer 9000 filas para usar ocho. El trámite lo
 * exige **un país concreto**, así que la lista es corta, se escribe una vez y se lee de un vistazo.
 *
 * El día que haya un maestro de aeropuertos, esto pasa a consultarlo y los `case` se quedan como
 * están: lo que declara este enum es **qué países exigen trámite**, que no lo dice ningún maestro.
 *
 * ⚠️ **Están TODOS los internacionales del país, no sólo el del viaje.** Con sólo `PUJ`, un grupo
 * que saliera por Santo Domingo daría «no se pudo comprobar» —o peor, «falta la salida»— y el
 * motivo real sería una lista corta, que es de las cosas que no se encuentran mirando el código
 * del validador.
 */
enum PaisDeControlEnum: string
{
    case REPUBLICA_DOMINICANA = 'DO';

    public function label(): string
    {
        return match ($this) {
            self::REPUBLICA_DOMINICANA => 'República Dominicana',
        };
    }

    /** Cómo llama el propio país a su trámite: es lo que el pasajero va a buscar. */
    public function tramite(): string
    {
        return match ($this) {
            self::REPUBLICA_DOMINICANA => 'E-Ticket',
        };
    }

    /**
     * Los aeropuertos internacionales del país, en IATA.
     *
     * @return list<string>
     */
    public function aeropuertos(): array
    {
        return match ($this) {
            self::REPUBLICA_DOMINICANA => ['PUJ', 'SDQ', 'POP', 'STI', 'LRM', 'AZS', 'JBQ', 'BRX'],
        };
    }

    public function esSuyo(?string $iata): bool
    {
        return $iata !== null && in_array(mb_strtoupper(trim($iata)), $this->aeropuertos(), true);
    }
}
