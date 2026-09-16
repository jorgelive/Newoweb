<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\PaisDeControlEnum;

/**
 * Con qué vuelo ENTRA una persona a un país y con cuál SALE.
 *
 * ── El hueco que tapa ───────────────────────────────────────────────────────
 * «Los vuelos están, los pasaportes están, pero no se sabe contra cuál poner.» Eso es literalmente
 * lo único que faltaba para controlar el E-Ticket: el trámite declara un vuelo de entrada y uno de
 * salida con sus fechas, y hasta ahora nada en el sistema sabía cuáles eran **los de esa persona**.
 *
 * ── Gemelo de {@see \App\Cotizacion\Service\CadenaDeAlojamiento} ────────────
 * Aquélla contesta «qué hotel cubre esta noche» recorriendo el itinerario; ésta contesta «qué vuelo
 * cruza esta frontera» recorriendo los vuelos del pasajero. Misma forma a propósito: objeto puro,
 * sin base de datos, construido desde fuera y probado solo. Una regla de negocio que no se puede
 * probar es una regla que cambia de criterio sin que nadie se entere.
 *
 * ── La regla, y por qué es ésta ─────────────────────────────────────────────
 * ```
 *   entrada = el PRIMER vuelo cuyo DESTINO es del país       (por hora de llegada)
 *   salida  = el primer vuelo cuyo ORIGEN es del país
 *             y que despega DESPUÉS de haber entrado
 * ```
 *
 * 🔑 **La escala no cuenta como entrada, y sale gratis.** Un `LIM→PTY` + `PTY→PUJ` tiene un solo
 * tramo con destino dominicano, así que preguntar por el destino ya descarta Panamá sin ninguna
 * regla sobre escalas. Lo mismo a la vuelta: de `PUJ→PTY` + `PTY→LIM`, sólo el primero sale del
 * país.
 *
 * ⚠️ **La salida se busca DESPUÉS de la entrada, y no es cosmético.** Sin esa condición, un viaje
 * que empezara dentro del país —o unos vuelos cargados en desorden— emparejarían la salida con la
 * estancia anterior, y el veredicto diría «la fecha de salida no coincide» señalando un dato
 * correcto. Un control que acusa en falso se deja de mirar entero.
 *
 * ⚠️ **Sólo se resuelve UNA estancia.** Quien entra, sale y vuelve a entrar necesita dos trámites,
 * y esto describe el primero. No se inventa: {@see self::hayMasEntradas()} lo dice, y quien juzgue
 * decidirá que eso es «no se puede comprobar» y no «está mal».
 */
final readonly class CruceDeFrontera
{
    private function __construct(
        public PaisDeControlEnum $pais,
        public ?CotizacionVuelo $entrada,
        public ?CotizacionVuelo $salida,
        /** Cuántos vuelos más entran al país después de la salida: señal de segunda estancia. */
        public int $entradasExtra = 0,
    ) {}

    /**
     * @param iterable<CotizacionVuelo> $vuelos los del pasajero, no los del expediente
     */
    public static function de(iterable $vuelos, PaisDeControlEnum $pais): self
    {
        $ordenados = [];

        foreach ($vuelos as $vuelo) {
            // Sin las dos horas no se puede ordenar ni comparar, y un vuelo a medio cargar
            // colocado por casualidad al principio decidiría la entrada de todo el mundo.
            if ($vuelo->getSalida() === null || $vuelo->getLlegada() === null) {
                continue;
            }

            $ordenados[] = $vuelo;
        }

        usort(
            $ordenados,
            static fn (CotizacionVuelo $a, CotizacionVuelo $b): int => $a->getSalida() <=> $b->getSalida(),
        );

        $entrada = null;

        foreach ($ordenados as $vuelo) {
            if ($pais->esSuyo($vuelo->getDestino())) {
                $entrada = $vuelo;
                break;
            }
        }

        if ($entrada === null) {
            return new self($pais, null, null);
        }

        $salida = null;

        foreach ($ordenados as $vuelo) {
            if ($pais->esSuyo($vuelo->getOrigen()) && $vuelo->getSalida() >= $entrada->getLlegada()) {
                $salida = $vuelo;
                break;
            }
        }

        // Una segunda entrada después de haber salido es otra estancia, y otro trámite.
        $extra = 0;

        if ($salida !== null) {
            foreach ($ordenados as $vuelo) {
                if ($pais->esSuyo($vuelo->getDestino()) && $vuelo->getSalida() >= $salida->getLlegada()) {
                    ++$extra;
                }
            }
        }

        return new self($pais, $entrada, $salida, $extra);
    }

    /** ¿Se sabe lo suficiente para juzgar un trámite de entrada y salida? */
    public function estaCompleto(): bool
    {
        return $this->entrada !== null && $this->salida !== null;
    }

    /**
     * ⚠️ Con más de una estancia, este objeto describe sólo la primera. Quien juzgue tiene que
     * tratarlo como «no se puede comprobar»: acusar con la estancia equivocada es peor que callar.
     */
    public function hayMasEntradas(): bool
    {
        return $this->entradasExtra > 0;
    }

    /** Por qué no se puede juzgar, en una frase para quien lo lea. Cadena vacía = sí se puede. */
    public function porQueNoSePuede(): string
    {
        if ($this->entrada === null) {
            return sprintf('no tiene ningún vuelo que entre a %s', $this->pais->label());
        }

        if ($this->salida === null) {
            return sprintf('no tiene ningún vuelo que salga de %s después de entrar', $this->pais->label());
        }

        if ($this->hayMasEntradas()) {
            return sprintf(
                'entra a %s más de una vez: hacen falta %d trámites y esto sólo describe el primero',
                $this->pais->label(),
                $this->entradasExtra + 1,
            );
        }

        return '';
    }
}
