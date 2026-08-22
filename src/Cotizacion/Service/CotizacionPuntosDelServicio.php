<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionSegmento;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\PuntosDeServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Dónde recoge y dónde deja cada servicio DE UNA COTIZACIÓN.
 *
 * El hermano de {@see \App\Travel\Service\TravelPuntosDelServicio} para el otro lado del muro.
 * Misma regla, distinta fuente: allí los segmentos vienen de la plantilla del catálogo; aquí, de
 * los `CotizacionSegmento` de esta cotización, que es lo que el operador ve y ha podido reordenar.
 *
 * ## Por qué existe en vez de reusar el del catálogo
 *
 * Porque una cotización **no es su plantilla**: se le añaden o quitan segmentos, se reordenan, y
 * hay servicios armados a medida sin plantilla ninguna. Leer los extremos del itinerario maestro
 * enseñaría al operador lo que dice el catálogo, no lo que dice su cotización — y justo la
 * diferencia entre ambos es lo que viene a buscar.
 *
 * ## Por qué se calcula aquí y no en el navegador
 *
 * Porque la regla ya está escrita una vez, y escribirla otra en TypeScript la pone a divergir. El
 * precio es que refleja lo **guardado**: reordenar segmentos sin guardar no se ve hasta guardar.
 * Se aceptó a cambio de no tener dos versiones de «cuál es el último segmento del día».
 *
 * ## Lo que NO resuelve
 *
 * Cuál hotel. `ALOJAMIENTO` viaja como modo hasta la orden de servicio, donde la reserva dice de
 * quién es la estancia. Aquí sólo interesa **que esté declarado**, que es lo que delata el hueco.
 */
final readonly class CotizacionPuntosDelServicio
{
    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Los puntos de todos los servicios de una cotización, listos para serializar.
     *
     * @return array<string, array{
     *     aplica: bool,
     *     inicio: array{modo: string, texto: ?string},
     *     fin: array{modo: string, texto: ?string},
     *     tieneFin: bool,
     *     completo: bool,
     *     faltantes: list<string>,
     *     detalle: list<array{componente: string, tipo: string, inicio: string, fin: ?string}>
     * }>
     */
    public function paraCotizacion(Cotizacion $cotizacion): array
    {
        /** @var list<CotizacionCotservicio> $servicios */
        $servicios = $cotizacion->getCotservicios()->toArray();
        $maestros = $this->maestrosDe($servicios);
        $salida = [];

        foreach ($servicios as $servicio) {
            $id = $servicio->getId()?->toRfc4122();

            if ($id === null) {
                continue;
            }

            $salida[$id] = $this->paraServicio($servicio, $maestros);
        }

        return $salida;
    }

    /**
     * Un servicio: la línea de cabecera y el detalle por componente.
     *
     * @param array<string, TravelSegmento> $maestros
     *
     * @return array{
     *     aplica: bool,
     *     inicio: array{modo: string, texto: ?string},
     *     fin: array{modo: string, texto: ?string},
     *     tieneFin: bool,
     *     completo: bool,
     *     faltantes: list<string>,
     *     detalle: list<array{componente: string, tipo: string, inicio: string, fin: ?string}>
     * }
     */
    public function paraServicio(CotizacionCotservicio $servicio, array $maestros): array
    {
        $porDia = $this->segmentosPorDia($servicio);
        $detalle = [];
        $cabecera = null;
        $faltantes = [];

        foreach ($servicio->getCotcomponentes() as $comp) {
            $tipo = ComponenteTipoEnum::tryFrom((string) $comp->getTipo());

            if ($tipo === null || $tipo->puntosDeServicio() === PuntosDeServicio::NINGUNO) {
                continue;
            }

            // Un componente cancelado o sustituido conserva su fila —aquí no se borra— pero ya no
            // se opera. Contarlo metía su hueco en `faltantes` y, si era el abarcador, le dejaba
            // la CABECERA del servicio: el operador veía en la tarjeta el recojo de algo que ya
            // no ocurre, y en ámbar por un dato que nadie tiene que rellenar.
            if (!$comp->estaVivo()) {
                continue;
            }

            $resuelto = $this->resolverComponente($comp, $tipo, $porDia, $maestros);

            $detalle[] = [
                'componente' => $this->nombreDe($comp),
                'tipo' => $tipo->value,
                'inicio' => $this->texto($resuelto['inicioModo'], $resuelto['inicioSeg'], $maestros, lado: 'inicio'),
                'fin' => $resuelto['tieneFin']
                    ? $this->texto($resuelto['finModo'], $resuelto['finSeg'], $maestros, lado: 'fin')
                    : null,
            ];

            // ⚠️ La CABECERA es el servicio que abarca el día si lo hay, y sólo si no, el primero
            // que tenga extremos. Al revés —el primero que aparezca— la línea del día la marcaría
            // un ticket de bus de dos paradas en vez de la excursión entera, que es la que el
            // operador está mirando.
            if ($cabecera === null || ($comp->isHoraServicioCompleto() && !$cabecera['abarca'])) {
                $cabecera = $resuelto + ['abarca' => $comp->isHoraServicioCompleto()];
            }

            foreach ($this->huecosDe($resuelto) as $hueco) {
                $faltantes[] = $this->nombreDe($comp) . ': ' . $hueco;
            }
        }

        if ($cabecera === null) {
            return [
                // ⚠️ `aplica: false` no es «falta el dato»: es que este servicio no recoge ni
                // deja a nadie —un alojamiento, un ticket, una comida—. La vista tiene que poder
                // distinguirlo o pintará un aviso rojo en la mitad de la cotización, y entonces
                // el rojo deja de significar nada.
                'aplica' => false,
                'inicio' => ['modo' => PuntoModoEnum::SIN_DEFINIR->value, 'texto' => null],
                'fin' => ['modo' => PuntoModoEnum::SIN_DEFINIR->value, 'texto' => null],
                'tieneFin' => false,
                // Un servicio sin nada que recoja ni deje —sólo tickets y comidas— está completo.
                // Sacarlo en rojo llenaría la vista de avisos que no se pueden resolver.
                'completo' => true,
                'faltantes' => [],
                'detalle' => [],
            ];
        }

        return [
            'aplica' => true,
            'inicio' => [
                'modo' => $cabecera['inicioModo']->value,
                'texto' => $this->texto($cabecera['inicioModo'], $cabecera['inicioSeg'], $maestros, lado: 'inicio'),
            ],
            'fin' => [
                'modo' => $cabecera['tieneFin'] ? $cabecera['finModo']->value : PuntoModoEnum::SIN_DEFINIR->value,
                'texto' => $cabecera['tieneFin']
                    ? $this->texto($cabecera['finModo'], $cabecera['finSeg'], $maestros, lado: 'fin')
                    : null,
            ],
            'tieneFin' => $cabecera['tieneFin'],
            'completo' => $faltantes === [],
            'faltantes' => $faltantes,
            'detalle' => $detalle,
        ];
    }


    /**
     * Los extremos de UN componente concreto, con el modo además del texto.
     *
     * Lo necesita La Biblia, que opera servicio a servicio y tiene que distinguir «el alojamiento
     * del pasajero» de un punto fijo: el primero todavía le falta resolver **cuál** hotel, y eso
     * sólo lo sabe con las fechas del expediente delante.
     *
     * `aplica: false` = este tipo no recoge ni deja a nadie.
     *
     * @param array<string, TravelSegmento> $maestros
     *
     * @return array{
     *     aplica: bool,
     *     inicioModo: PuntoModoEnum, inicioTexto: ?string,
     *     finModo: PuntoModoEnum, finTexto: ?string,
     *     tieneFin: bool
     * }
     */
    public function paraComponente(CotizacionCotcomponente $comp, array $maestros): array
    {
        $tipo = ComponenteTipoEnum::tryFrom((string) $comp->getTipo());

        if ($tipo === null || $tipo->puntosDeServicio() === PuntosDeServicio::NINGUNO) {
            return [
                'aplica' => false,
                'inicioModo' => PuntoModoEnum::SIN_DEFINIR, 'inicioTexto' => null,
                'finModo' => PuntoModoEnum::SIN_DEFINIR, 'finTexto' => null,
                'tieneFin' => false,
            ];
        }

        $servicio = $comp->getCotservicio();
        $porDia = $servicio === null ? [] : $this->segmentosPorDia($servicio);
        $r = $this->resolverComponente($comp, $tipo, $porDia, $maestros);

        return [
            'aplica' => true,
            'inicioModo' => $r['inicioModo'],
            'inicioTexto' => $this->texto($r['inicioModo'], $r['inicioSeg'], $maestros, lado: 'inicio'),
            'finModo' => $r['finModo'],
            'finTexto' => $r['tieneFin'] ? $this->texto($r['finModo'], $r['finSeg'], $maestros, lado: 'fin') : null,
            'tieneFin' => $r['tieneFin'],
        ];
    }

    /**
     * Los `TravelSegmento` de los servicios que se le pasen, en UNA consulta.
     *
     * Público porque La Biblia y la orden resuelven servicio a servicio y necesitan el mapa sin
     * pasar por una cotización entera.
     *
     * @param list<CotizacionCotservicio> $servicios
     *
     * @return array<string, TravelSegmento>
     */
    public function maestrosDeServicios(array $servicios): array
    {
        return $this->maestrosDe($servicios);
    }

    /**
     * De qué segmento salen los extremos de un componente.
     *
     * @param array<int, list<CotizacionSegmento>> $porDia
     * @param array<string, TravelSegmento>        $maestros
     *
     * @return array{inicioModo: PuntoModoEnum, inicioSeg: ?CotizacionSegmento, finModo: PuntoModoEnum, finSeg: ?CotizacionSegmento, tieneFin: bool}
     */
    private function resolverComponente(
        CotizacionCotcomponente $comp,
        ComponenteTipoEnum $tipo,
        array $porDia,
        array $maestros,
    ): array {
        $propio = $comp->getCotsegmento();
        $inicioSeg = $propio;
        $finSeg = $propio;

        // La misma regla del catálogo: quien abarca el día empieza en el primer segmento de ese
        // día y termina en el último, porque cuelga del de recojo y ése no sabe dónde acaba.
        if ($comp->isHoraServicioCompleto() && $propio !== null) {
            $delDia = $porDia[$propio->getDia()] ?? [];

            if ($delDia !== []) {
                        // ⚠️ **Es un OVERRIDE, no un aplastamiento.** El extremo del día manda **si declara
                // algo**; si no, se cae al segmento del que cuelga el componente.
                //
                // La primera versión cogía el primero y el último del día sin mirar, y eso borraba
                // información: un pool colgado de un segmento que SÍ dice dónde recoge, dentro de un día
                // cuyo primer segmento no dice nada, se quedaba sin punto de recojo. Hoy no se nota
                // porque todos los abarcadores cuelgan del primer segmento de su día —y entonces los dos
                // caminos coinciden—, pero deja de ser cierto en cuanto se cuelgue uno de un segmento
                // intermedio, que es justo lo que permite el modelo.
                //
                // En este orden y no al revés: si el día declara un extremo, ése es el bueno — es lo que
                // hace que «Retorno al centro de Cusco» mande sobre el segmento de recojo.
                $primero = $delDia[0];
                $ultimo = $delDia[count($delDia) - 1];

                $inicioSeg = $this->modo($primero, $maestros, lado: 'inicio')->esDeclarado() ? $primero : $propio;
                $finSeg = $this->modo($ultimo, $maestros, lado: 'fin')->esDeclarado() ? $ultimo : $propio;
            }
        }

        return [
            'inicioModo' => $this->modo($inicioSeg, $maestros, lado: 'inicio'),
            'inicioSeg' => $inicioSeg,
            'finModo' => $this->modo($finSeg, $maestros, lado: 'fin'),
            'finSeg' => $finSeg,
            'tieneFin' => $tipo->puntosDeServicio()->programaFin(),
        ];
    }

    /**
     * @param array{inicioModo: PuntoModoEnum, inicioSeg: ?CotizacionSegmento, finModo: PuntoModoEnum, finSeg: ?CotizacionSegmento, tieneFin: bool} $r
     *
     * @return list<string>
     */
    private function huecosDe(array $r): array
    {
        $huecos = [];

        if (!$r['inicioModo']->esDeclarado()) {
            $huecos[] = 'sin punto de recojo';
        }

        if ($r['tieneFin'] && !$r['finModo']->esDeclarado()) {
            $huecos[] = 'sin punto de entrega';
        }

        return $huecos;
    }

    /** @param array<string, TravelSegmento> $maestros */
    private function modo(?CotizacionSegmento $seg, array $maestros, string $lado): PuntoModoEnum
    {
        $maestro = $this->maestroDe($seg, $maestros);

        if ($maestro === null) {
            return PuntoModoEnum::SIN_DEFINIR;
        }

        return $lado === 'inicio' ? $maestro->getInicioModo() : $maestro->getFinModo();
    }

    /** @param array<string, TravelSegmento> $maestros */
    private function texto(PuntoModoEnum $modo, ?CotizacionSegmento $seg, array $maestros, string $lado): ?string
    {
        if ($modo === PuntoModoEnum::ALOJAMIENTO) {
            return 'el alojamiento del pasajero';
        }

        if ($modo !== PuntoModoEnum::FIJO) {
            return null;
        }

        $maestro = $this->maestroDe($seg, $maestros);
        $punto = $lado === 'inicio' ? $maestro?->getInicioPunto() : $maestro?->getFinPunto();

        return $punto?->getNombre();
    }

    /** @param array<string, TravelSegmento> $maestros */
    private function maestroDe(?CotizacionSegmento $seg, array $maestros): ?TravelSegmento
    {
        $id = $seg?->getSegmentoMaestroId();

        return $id === null ? null : ($maestros[$id] ?? null);
    }

    /**
     * Los segmentos de un servicio agrupados por día y en orden.
     *
     * @return array<int, list<CotizacionSegmento>>
     */
    private function segmentosPorDia(CotizacionCotservicio $servicio): array
    {
        /** @var list<CotizacionSegmento> $segmentos */
        $segmentos = $servicio->getCotsegmentos()->toArray();

        usort(
            $segmentos,
            static fn (CotizacionSegmento $a, CotizacionSegmento $b): int
                => [$a->getDia(), $a->getOrden()] <=> [$b->getDia(), $b->getOrden()]
        );

        $porDia = [];

        foreach ($segmentos as $seg) {
            $porDia[$seg->getDia()][] = $seg;
        }

        return $porDia;
    }

    /**
     * Los `TravelSegmento` de toda la cotización, en UNA consulta.
     *
     * Una por servicio serían decenas al abrir una cotización de dos semanas, y esto se pide en
     * cada carga del editor.
     *
     * @param list<CotizacionCotservicio> $servicios
     *
     * @return array<string, TravelSegmento>
     */
    private function maestrosDe(array $servicios): array
    {
        $ids = [];

        foreach ($servicios as $servicio) {
            foreach ($servicio->getCotsegmentos() as $seg) {
                $id = $seg->getSegmentoMaestroId();

                // Un servicio a medida no tiene maestro, y no es un error: significa que sus
                // puntos no están declarados en ningún sitio, que es justo lo que hay que ver.
                if ($id !== null && Uuid::isValid($id)) {
                    $ids[$id] = Uuid::fromString($id);
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        // ⚠️ `findBy()` y no un QueryBuilder con `IN (:ids)`.
        //
        // El `setParameter()` de un UUID necesita su tipo explícito, y para una LISTA ese tipo
        // —`uuid[]`— no está registrado en DBAL: revienta con «Unknown column type». `findBy()`
        // convierte por los metadatos de la entidad y no hay nada que acertar. Es la misma
        // familia de trampa que documenta TravelPuntosDelServicio::extremosDelDia(), sólo que
        // ésta al menos falla en voz alta en vez de devolver cero filas.
        /** @var list<TravelSegmento> $encontrados */
        $encontrados = $this->em->getRepository(TravelSegmento::class)
            ->findBy(['id' => array_values($ids)]);

        $mapa = [];

        foreach ($encontrados as $segmento) {
            $mapa[(string) $segmento->getId()] = $segmento;
        }

        return $mapa;
    }

    private function nombreDe(CotizacionCotcomponente $comp): string
    {
        foreach ($comp->getNombreSnapshot() as $fila) {
            $texto = trim((string) ($fila['content'] ?? ''));

            if ($texto !== '') {
                return $texto;
            }
        }

        return 'Componente sin nombre';
    }
}
