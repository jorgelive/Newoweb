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
 *   entrada = el PRIMER vuelo que va de FUERA a DENTRO del país
 *   salida  = el primero que va de DENTRO a FUERA, despegando después de entrar
 * ```
 *
 * 🔑 **Un cruce de frontera necesita los DOS lados, y ahí estaba el fallo.** La primera versión
 * preguntaba sólo por el destino para entrar y sólo por el origen para salir, y con eso un tramo
 * **doméstico** —`PUJ→SDQ`, dos aeropuertos dominicanos— cumplía la condición de salida.
 *
 * Escenario real que lo destapa: `LIM→SDQ` (18), `SDQ→PUJ` (19), `PUJ→LIM` (22). La salida elegida
 * era `SDQ→PUJ`, o sea un vuelo interno, y el trámite correcto salía **OBSERVADO con dos
 * discrepancias falsas** —vuelo y fecha de salida—. En un grupo que llega por Santo Domingo y sigue
 * a Punta Cana, eso es el grupo entero acusado a la vez.
 *
 * ⚠️ La cabecera decía «la escala sale gratis», y era verdad **sólo para escalas fuera del país**:
 * de `LIM→PTY` + `PTY→PUJ`, Panamá se cae solo. La escala DENTRO era justo el caso que no cubría.
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
            if (self::entraAlPais($vuelo, $pais)) {
                $entrada = $vuelo;
                break;
            }
        }

        if ($entrada === null) {
            return new self($pais, null, null);
        }

        $salida = null;

        foreach ($ordenados as $vuelo) {
            if (self::saleDelPais($vuelo, $pais) && $vuelo->getSalida() >= $entrada->getLlegada()) {
                $salida = $vuelo;
                break;
            }
        }

        // ⚠️ **Se cuentan TODAS las demás entradas, no sólo las posteriores a la salida.** Contar
        // sólo las de después dejaba ciego el caso que de verdad ocurre: dos vuelos de entrada
        // **antes** de salir. Pasa con un pasajero que quedó en dos subgrupos aéreos por un error
        // de carga, o con un vuelo reemplazado que sigue colgando del subgrupo — y entonces se
        // elegía el primero en silencio y se acusaba a la persona de haber puesto «mal» el vuelo
        // que sí voló. Dos entradas posibles es un dato roto: se denuncia, no se resuelve a ojo.
        $extra = 0;

        foreach ($ordenados as $vuelo) {
            if ($vuelo !== $entrada && self::entraAlPais($vuelo, $pais)) {
                ++$extra;
            }
        }

        return new self($pais, $entrada, $salida, $extra);
    }

    /** De fuera a dentro. Las dos mitades: un tramo doméstico no cruza ninguna frontera. */
    private static function entraAlPais(CotizacionVuelo $vuelo, PaisDeControlEnum $pais): bool
    {
        return $pais->esSuyo($vuelo->getDestino()) && !$pais->esSuyo($vuelo->getOrigen());
    }

    /** De dentro a fuera. */
    private static function saleDelPais(CotizacionVuelo $vuelo, PaisDeControlEnum $pais): bool
    {
        return $pais->esSuyo($vuelo->getOrigen()) && !$pais->esSuyo($vuelo->getDestino());
    }

    /** ¿Se sabe lo suficiente para juzgar un trámite de entrada y salida? */
    public function estaCompleto(): bool
    {
        return $this->entrada !== null && $this->salida !== null;
    }

    /**
     * ⚠️ Con más de un vuelo de entrada, este objeto describe sólo el primero. Quien juzgue tiene
     * que tratarlo como «no se puede comprobar»: acusar con el vuelo equivocado es peor que callar,
     * y si son dos subgrupos mal asignados el acusado sería quien no tiene culpa.
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
                'tiene %d vuelos que entran a %s: o son dos estancias —y entonces hacen falta dos '
                .'trámites— o hay un vuelo de más colgando de su subgrupo. Hay que mirarlo antes de '
                .'juzgar el trámite',
                $this->entradasExtra + 1,
                $this->pais->label(),
            );
        }

        return '';
    }
}
