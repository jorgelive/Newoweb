<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Service\CadenaDeAlojamiento;
use App\Cotizacion\Service\CadenaDeAlojamientoBuilder;
use App\Cotizacion\Service\CotizacionPuntosDelServicio;
use App\Operacion\Entity\OperacionServicio;
use App\Travel\Enum\PuntoModoEnum;
use DateTimeImmutable;

/**
 * El último tramo: de «el alojamiento del pasajero» a «Hotel Terra — Calle Unión 184, Cusco».
 *
 * Junta las tres capas que hasta ahora vivían sueltas:
 *
 * ```
 * 1. el override del operador   OperacionServicio::$puntoRecojo   — si lo hay, manda
 * 2. lo que dice el catálogo    CotizacionPuntosDelServicio       — punto fijo o «su alojamiento»
 * 3. cuál alojamiento           CadenaDeAlojamiento               — con las fechas del expediente
 * ```
 *
 * El orden es el que es porque cada capa sabe algo que la de abajo no puede saber: el catálogo no
 * sabe en qué hotel duerme este grupo, y el expediente no sabe que a **este** cliente lo recogemos
 * en la puerta de atrás porque llega en bus.
 *
 * ⚠️ **Una noche sin alojamiento NO cae al hotel anterior.** Se devuelve `null` y se anota el
 * aviso. En un trek el pasajero duerme en campamento, y el último hotel conocido es la respuesta
 * plausible que manda al conductor a cuatro horas de donde está la gente. Los campamentos se
 * declaran como `TravelPunto` con modo fijo — ver `docs/Travel.md` §11 quater.
 */
final class OperacionPuntosDelServicio
{
    /** @var array<string, CadenaDeAlojamiento> Una cadena por cotización, dentro de la petición. */
    private array $cadenas = [];

    public function __construct(
        private readonly CotizacionPuntosDelServicio $puntosDelCatalogo,
        private readonly CadenaDeAlojamientoBuilder $cadenaBuilder,
    ) {}

    /**
     * @param bool $conOverride `false` devuelve SÓLO lo que dice el catálogo, ignorando lo que
     *                          escribió el operador. Es lo que el panel usa de marcador de
     *                          posición: enseña qué saldría si vaciara el campo, que es la única
     *                          forma de que se entienda que vacío no es «sin punto» sino
     *                          «el del catálogo».
     */
    public function para(OperacionServicio $servicio, bool $conOverride = true): PuntosOperativos
    {
        $override = $conOverride
            ? ['recojo' => $servicio->getPuntoRecojo(), 'entrega' => $servicio->getPuntoEntrega()]
            : ['recojo' => null, 'entrega' => null];

        $comp = $servicio->getCotizacionComponente();

        // Sin componente cotizado no hay nada que derivar, pero el override sigue valiendo: es
        // información que escribió una persona, y tirarla porque falte el enlace sería perder
        // justo el dato que alguien se molestó en poner.
        if ($comp === null) {
            if ($override['recojo'] === null && $override['entrega'] === null) {
                return PuntosOperativos::noAplica();
            }

            return new PuntosOperativos(
                aplica: true,
                recojo: $override['recojo'],
                entrega: $override['entrega'],
                tieneEntrega: $override['entrega'] !== null,
                recojoEsOverride: $override['recojo'] !== null,
                entregaEsOverride: $override['entrega'] !== null,
                avisos: [],
            );
        }

        $cotservicio = $comp->getCotservicio();
        $maestros = $cotservicio === null ? [] : $this->puntosDelCatalogo->maestrosDeServicios([$cotservicio]);
        $derivado = $this->puntosDelCatalogo->paraComponente($comp, $maestros);

        if (!$derivado['aplica'] && $override['recojo'] === null && $override['entrega'] === null) {
            return PuntosOperativos::noAplica();
        }

        $fecha = $servicio->getFechaServicio();
        $cadena = $this->cadenaDe($cotservicio?->getCotizacion());
        $avisos = [];

        $recojo = $override['recojo'] ?? $this->resolver(
            $derivado['inicioModo'],
            $derivado['inicioTexto'],
            $cadena,
            $fecha,
            lado: 'recojo',
            avisos: $avisos,
        );

        $entrega = null;

        if ($derivado['tieneFin'] || $override['entrega'] !== null) {
            $entrega = $override['entrega'] ?? $this->resolver(
                $derivado['finModo'],
                $derivado['finTexto'],
                $cadena,
                $fecha,
                lado: 'entrega',
                avisos: $avisos,
            );
        }

        return new PuntosOperativos(
            aplica: true,
            recojo: $recojo,
            entrega: $entrega,
            tieneEntrega: $derivado['tieneFin'] || $override['entrega'] !== null,
            recojoEsOverride: $override['recojo'] !== null,
            entregaEsOverride: $override['entrega'] !== null,
            avisos: $avisos,
        );
    }

    /**
     * Convierte el modo del catálogo en la línea que lee el proveedor.
     *
     * @param list<string> $avisos
     */
    private function resolver(
        PuntoModoEnum $modo,
        ?string $textoDelCatalogo,
        ?CadenaDeAlojamiento $cadena,
        ?DateTimeImmutable $fecha,
        string $lado,
        array &$avisos,
    ): ?string {
        if ($modo === PuntoModoEnum::SIN_DEFINIR) {
            // ⚠️ Un hueco tiene que DECIRSE. Devolver `null` en silencio deja el renglón en
            // blanco en la orden y a nadie con qué buscar la causa: el operador no puede saber
            // si es que no aplica, si falta declararlo en el segmento o si algo falló. El aviso
            // dice dónde se arregla.
            $avisos[] = sprintf('%s: el catálogo no lo declara. Se pone en el SEGMENTO del maestro, o a mano aquí.', $lado);

            return null;
        }

        if ($modo !== PuntoModoEnum::ALOJAMIENTO) {
            return $textoDelCatalogo;
        }

        if ($cadena === null || $fecha === null) {
            $avisos[] = sprintf('%s: es el alojamiento del pasajero, pero falta la fecha o el expediente', $lado);

            return null;
        }

        // Recoger es salir de donde DURMIÓ; dejar es llegar a donde DORMIRÁ. El día que cambia
        // de hotel, las dos respuestas son distintas y las dos son correctas.
        $estancia = $lado === 'recojo' ? $cadena->dondeDurmio($fecha) : $cadena->dondeDormira($fecha);

        if ($estancia === null) {
            $avisos[] = sprintf(
                '%s: no hay alojamiento esa noche (%s). Si es un campamento, decláralo como punto fijo en el segmento.',
                $lado,
                $fecha->format('d/m/Y')
            );

            return null;
        }

        if (!$estancia->estaCompleta()) {
            $avisos[] = sprintf('%s: «%s» no tiene dirección en su ficha', $lado, $estancia->hotel);
        }

        return $estancia->paraLaOrden();
    }

    private function cadenaDe(?Cotizacion $cotizacion): ?CadenaDeAlojamiento
    {
        $id = $cotizacion?->getId()?->toRfc4122();

        if ($id === null) {
            return null;
        }

        // Una orden recorre decenas de servicios del mismo expediente: sin esto se reconstruiría
        // la cadena entera —y sus consultas— una vez por línea.
        return $this->cadenas[$id] ??= $this->cadenaBuilder->para($cotizacion);
    }
}
