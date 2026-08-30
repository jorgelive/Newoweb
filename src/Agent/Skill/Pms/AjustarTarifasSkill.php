<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsTarifaRango;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Pms\Service\Tarifa\PmsTarifaCalculadora;
use App\Security\Roles;
use App\Entity\Maestro\MaestroMoneda;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sube o baja el precio de unas fechas. **Escribe, y viaja a todos los portales.**
 *
 * «Baja 10 % la Casita 1 en las noches libres de agosto» era media hora de calendario: mirar
 * qué rango gana cada día, calcular el nuevo precio, saltarse las noches vendidas y crear los
 * rangos a mano sin pisar lo que ya había.
 *
 * ### 🏆 Se ajusta sobre el precio VIGENTE, no sobre la tarifa base
 *
 * El punto que lo hace útil. Cada noche tiene un precio ganador —el rango que se impone según
 * `important`, prioridad y duración ({@see PmsTarifaCalculadora})— y el ajuste se aplica sobre
 * **ese**. Calcularlo sobre la tarifa base daría un precio sin relación con lo que hoy se
 * vende, y bajar un 10 % acabaría siendo una subida.
 *
 * ### 🧮 Varias tarifas en el tramo → promedio
 *
 * Un tramo puede cruzar dos temporadas (65 tres noches, 45 dos). Un rango nuevo tiene **un
 * solo precio**, así que se parte del promedio de las noches afectadas y sobre él se aplica el
 * ajuste. La previsualización enseña ese promedio y los precios de los que sale: el operador
 * tiene que ver que está aplanando dos temporadas en una, no descubrirlo después.
 *
 * ### 🥇 Prioridad = la mayor que pisa, + 1
 *
 * No se toca ningún rango existente: se crea uno nuevo **por encima**. Así el ajuste se puede
 * deshacer borrando una fila, y lo que había debajo sigue intacto por si el precio nuevo era
 * temporal. La prioridad sale de los rangos que de verdad ganaban esas noches —no del máximo
 * global de la unidad—, porque lo único que hay que superar es lo que se está pisando.
 *
 * ### ✂️ Las noches vendidas parten el rango
 *
 * Con `solo_libres`, las noches ocupadas se descartan; y como un `PmsTarifaRango` es continuo,
 * los huecos obligan a crear **un rango por tramo seguido**. Es lo que se quiere: cambiar el
 * precio de una noche ya vendida no cambia lo que se cobró, sólo ensucia el histórico.
 *
 * ⚠️ **`fechaFin` es EXCLUSIVA.** Una noche suelta es `fin = inicio + 1 día`; con `fin = inicio`
 * el flattener descarta el rango entero y se guarda sin error sin hacer nada.
 */
final readonly class AjustarTarifasSkill implements SkillInterface, SkillDominioInterface
{
    private const string TZ = 'America/Lima';

    /** Techo del tramo. Más de esto no es un ajuste puntual, es rehacer el tarifario. */
    private const int MAX_NOCHES = 120;

    public function __construct(
        private EntityManagerInterface $em,
        private PmsTarifaCalculadora $tarifas,
        private PmsDisponibilidadService $disponibilidad,
    ) {}

    public function nombre(): string
    {
        return 'ajustar_tarifas';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Sube o baja el precio de una casita en unas fechas, creando un rango '
                . 'de tarifa nuevo por encima de los que ya hay. Úsala para «baja 10% la casita '
                . '1 en agosto», «sube 5 dólares el fin de semana» o «rebaja las noches libres '
                . 'del próximo mes». El ajuste se calcula SOBRE EL PRECIO VIGENTE de cada noche '
                . '—el rango que hoy gana—, no sobre la tarifa base. MODIFICA DATOS Y SALE A '
                . 'TODOS LOS PORTALES: llama SIEMPRE primero con confirmado=false y enséñale al '
                . 'operador la previsualización entera —el precio de ahora, el nuevo y cuántas '
                . 'noches cambian— antes de pedirle el sí. Si el tramo cruza varias tarifas '
                . 'distintas te devolveré el promedio del que parto: DÍSELO, porque significa '
                . 'que dos temporadas quedan al mismo precio. Con solo_libres=true se saltan '
                . 'las noches ya vendidas, que es lo normal al hacer una promoción: cambiar el '
                . 'precio de una noche vendida no cambia lo que se cobró. Para ver precios sin '
                . 'tocar nada usa consultar_tarifas.',
            parametros: [
                SkillParameter::texto('casita', 'Nombre o slug de la casita.'),
                SkillParameter::texto('desde', 'Primer día del ajuste, YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Último día (la noche de salida), YYYY-MM-DD.'),
                SkillParameter::texto('ajuste', 'Cuánto cambiar. Admite importe fijo ("-5", '
                    . '"+10") o porcentaje ("-10%", "+15%"). El signo manda: sin signo se '
                    . 'entiende como subida.'),
                SkillParameter::booleano('solo_libres', 'true para ajustar sólo las noches que '
                    . 'no están vendidas. Es lo normal en una promoción.', requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación explícita '
                    . 'del operador. false para previsualizar.'),
            ],
        );
    }

    /**
     * Del negocio de ALOJAMIENTO: habla de reservas, estancias, casitas o su dinero.
     *
     * Recorta el catálogo, no los permisos — ver {@see SkillDominioInterface}.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $desde = $this->fecha($entrada['desde'] ?? null);
        $hasta = $this->fecha($entrada['hasta'] ?? null);
        $casitaPedida = trim((string) ($entrada['casita'] ?? ''));
        $ajusteTexto = trim((string) ($entrada['ajuste'] ?? ''));

        // Todo lo que falte, junto: preguntar la casita y luego el ajuste son dos viajes.
        $faltan = [];

        if ($casitaPedida === '') {
            $faltan[] = 'casita';
        }

        if ($desde === null || $hasta === null) {
            $faltan[] = 'fechas';
        }

        if ($ajusteTexto === '') {
            $faltan[] = 'ajuste';
        }

        if ($faltan !== []) {
            return SkillResult::ok([
                'falta_datos' => $faltan,
                'pregunta' => 'Pregúntale al operador, todo de una vez: qué casita, qué fechas, '
                    . 'y cuánto sube o baja (puede ser un importe como «-5» o un porcentaje '
                    . 'como «-10%»). Luego vuelve a llamarme con esos datos.',
            ]);
        }

        if ($hasta <= $desde) {
            return SkillResult::error('La fecha final tiene que ser posterior a la inicial.');
        }

        if ((int) $desde->diff($hasta)->days > self::MAX_NOCHES) {
            return SkillResult::error(sprintf(
                'El tramo pasa de %d noches. Eso ya no es un ajuste puntual: hazlo por partes o '
                . 'desde el calendario de tarifas.',
                self::MAX_NOCHES
            ));
        }

        $ajuste = $this->interpretarAjuste($ajusteTexto);

        if ($ajuste === null) {
            return SkillResult::error(
                'No entiendo el ajuste. Dímelo como importe ("-5", "+10") o como porcentaje '
                . '("-10%", "+15%").'
            );
        }

        $unidad = $this->resolverUnidad($casitaPedida);

        if ($unidad === null) {
            return SkillResult::error(sprintf(
                'No encuentro la casita «%s». Dime su nombre tal como aparece en el panel.',
                $casitaPedida
            ));
        }

        $diarios = $this->tarifas->preciosPorNoche($unidad, $desde, $hasta);

        if ($diarios === []) {
            return SkillResult::error(
                'Esas noches no tienen precio del que partir: sin tarifa cargada ni tarifa base '
                . 'activa no hay nada que ajustar. Ponle un precio antes.'
            );
        }

        $soloLibres = filter_var($entrada['solo_libres'] ?? false, FILTER_VALIDATE_BOOL);
        $ocupadas = $soloLibres ? $this->nochesOcupadas($unidad, $desde, $hasta) : [];

        $afectadas = array_filter(
            $diarios,
            static fn (array $d, string $dia): bool => !isset($ocupadas[$dia]),
            ARRAY_FILTER_USE_BOTH
        );

        if ($afectadas === []) {
            return SkillResult::ok([
                'casita' => $unidad->getNombre(),
                'aplicado' => false,
                'motivo' => 'todas_vendidas',
                'mensaje' => 'Todas esas noches ya están vendidas, así que no hay ninguna a la '
                    . 'que cambiarle el precio.',
            ]);
        }

        // 🧮 El promedio del que se parte. Un rango nuevo tiene UN precio, así que si el tramo
        // cruza dos temporadas hay que aplanarlas — y decirlo.
        $precios = array_map(static fn (array $d): float => (float) $d['price'], $afectadas);
        $promedio = array_sum($precios) / count($precios);
        $nuevo = round($this->aplicar($promedio, $ajuste), 2);

        if ($nuevo <= 0.0) {
            return SkillResult::error(sprintf(
                'Ese ajuste deja el precio en %.2f. Un precio de cero o negativo no se puede '
                . 'publicar: revisa el descuento con el operador.',
                $nuevo
            ));
        }

        $distintos = array_values(array_unique($precios));
        sort($distintos);

        $tramos = $this->tramosContinuos(array_keys($afectadas));
        $prioridad = $this->prioridadPorEncima($afectadas);
        $moneda = $this->monedaDe($afectadas, $unidad);
        $minStay = max(array_map(static fn (array $d): int => (int) $d['minStay'], $afectadas));

        $resumen = array_filter([
            'casita' => $unidad->getNombre(),
            'desde' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
            'noches_afectadas' => count($afectadas),
            'noches_vendidas_respetadas' => $ocupadas !== [] ? count($ocupadas) : null,
            'precio_de_ahora' => count($distintos) === 1
                ? sprintf('%.2f %s', $distintos[0], $moneda)
                : sprintf(
                    'varía entre %.2f y %.2f %s — promedio %.2f',
                    $distintos[0],
                    end($distintos),
                    $moneda,
                    $promedio
                ),
            // Se dice aparte cuando hay varias, porque es lo que el operador NO espera: dos
            // temporadas distintas van a quedar al mismo precio.
            'aviso_promedio' => count($distintos) > 1
                ? sprintf(
                    'Ojo: esas noches tienen %d precios distintos (%s). Un rango nuevo sólo '
                    . 'admite UNO, así que parto del promedio (%.2f) y las dejo todas a %.2f. '
                    . 'DÍSELO al operador antes de que apruebe.',
                    count($distintos),
                    implode(', ', array_map(static fn (float $p): string => sprintf('%.2f', $p), $distintos)),
                    $promedio,
                    $nuevo
                )
                : null,
            'ajuste' => $ajusteTexto,
            'precio_nuevo' => sprintf('%.2f %s', $nuevo, $moneda),
            'rangos_a_crear' => count($tramos),
            'prioridad_nueva' => $prioridad,
            'tramos' => array_map(
                static fn (array $t): string => sprintf('%s → %s', $t[0], $t[1]),
                $tramos
            ),
        ], static fn ($v) => $v !== null);

        if (!filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL)) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                'que_va_a_pasar' => $this->consecuencias($unidad, $tramos, $nuevo, $moneda, $prioridad),
                'pregunta_aprobacion' => sprintf(
                    '¿Aplico el ajuste? %s pasa de %s a %.2f %s en %d noche(s).',
                    $unidad->getNombre(),
                    count($distintos) === 1 ? sprintf('%.2f', $distintos[0]) : sprintf('~%.2f', $promedio),
                    $nuevo,
                    $moneda,
                    count($afectadas)
                ),
            ]);
        }

        $creados = [];

        foreach ($tramos as [$ini, $fin]) {
            $rango = new PmsTarifaRango();
            $rango->setUnidad($unidad);
            $rango->setFechaInicio(new DateTimeImmutable($ini, new DateTimeZone(self::TZ)));
            // ⚠️ `fechaFin` EXCLUSIVA: el tramo acaba la noche `$fin`, así que el rango termina
            // al día siguiente. Con `fin = inicio` el flattener lo descarta y no haría nada.
            $rango->setFechaFin(
                (new DateTimeImmutable($fin, new DateTimeZone(self::TZ)))->modify('+1 day')
            );
            $rango->setPrecio(number_format($nuevo, 2, '.', ''));
            $rango->setMoneda($this->monedaEntidad($afectadas, $unidad));
            $rango->setMinStay($minStay);
            $rango->setPrioridad($prioridad);
            $rango->setActivo(true);

            $this->em->persist($rango);
            $creados[] = sprintf('%s → %s', $ini, $fin);
        }

        $this->em->flush();

        return SkillResult::ok($resumen + [
            'aplicado' => true,
            'que_ha_pasado' => $this->consecuencias($unidad, $tramos, $nuevo, $moneda, $prioridad),
            'mensaje' => sprintf(
                'Creado%s %d rango(s) a %.2f %s con prioridad %d. Los rangos que había siguen '
                . 'ahí debajo: para deshacerlo basta con borrar el nuevo.',
                count($creados) === 1 ? '' : 's',
                count($creados),
                $nuevo,
                $moneda,
                $prioridad
            ),
        ]);
    }

    /**
     * Todo lo que dispara crear el rango.
     *
     * @param list<array{0: string, 1: string}> $tramos
     * @return list<string>
     */
    private function consecuencias(
        PmsUnidad $unidad,
        array $tramos,
        float $precio,
        string $moneda,
        int $prioridad
    ): array {
        $lista = [sprintf(
            'Se crea%s %d rango(s) de tarifa en %s a %.2f %s, con prioridad %d para que gane a '
            . 'los que ya había.',
            count($tramos) === 1 ? '' : 'n',
            count($tramos),
            $unidad->getNombre(),
            $precio,
            $moneda,
            $prioridad
        )];

        if (count($tramos) > 1) {
            $lista[] = sprintf(
                'Son %d rangos y no uno porque hay noches vendidas en medio: se respetan y el '
                . 'ajuste se parte en tramos.',
                count($tramos)
            );
        }

        $lista[] = sprintf(
            'El precio nuevo VIAJA A BEDS24 y de ahí a todos los portales: %s pasa a ofrecerse '
            . 'a %.2f %s esas noches.',
            $unidad->getNombre(),
            $precio,
            $moneda
        );

        $lista[] = 'NO se modifica ni se borra ningún rango existente: quedan debajo, así que '
            . 'deshacer el ajuste es borrar el rango nuevo y el precio anterior vuelve solo.';

        return $lista;
    }

    /**
     * La prioridad que hace falta para ganar a lo que hay.
     *
     * Del máximo de los rangos que HOY ganan esas noches, +1. No del máximo de la unidad: lo
     * único que hay que superar es lo que se está pisando, y coger el global inflaría las
     * prioridades sin motivo hasta que ya no signifiquen nada.
     *
     * Las noches que salían de la tarifa base no aportan prioridad —la base no es un rango—,
     * así que un tramo entero sin tarifa cargada estrena el rango con prioridad 1.
     *
     * @param array<string, array{price: float, minStay: int, currency: ?string, sourceId: string}> $afectadas
     */
    private function prioridadPorEncima(array $afectadas): int
    {
        $ids = [];

        foreach ($afectadas as $datos) {
            $uuid = PmsTarifaCalculadora::rangoDe((string) $datos['sourceId']);

            if ($uuid !== null) {
                $ids[$uuid] = true;
            }
        }

        if ($ids === []) {
            return 1;
        }

        $maxima = 0;

        foreach (array_keys($ids) as $uuid) {
            $rango = $this->em->getRepository(PmsTarifaRango::class)->find($uuid);

            if ($rango !== null) {
                $maxima = max($maxima, $rango->getPrioridad());
            }
        }

        return $maxima + 1;
    }

    /**
     * Parte una lista de fechas sueltas en tramos seguidos.
     *
     * Un `PmsTarifaRango` cubre un intervalo continuo, así que las noches vendidas en medio
     * obligan a crear varios. Devuelve pares `[primera noche, última noche]` — la última es
     * inclusiva aquí; el `+1 día` de `fechaFin` se hace al crear.
     *
     * @param list<string> $noches Fechas `Y-m-d`, en orden.
     * @return list<array{0: string, 1: string}>
     */
    private function tramosContinuos(array $noches): array
    {
        sort($noches);

        $tramos = [];
        $ini = null;
        $prev = null;

        foreach ($noches as $noche) {
            if ($ini === null) {
                $ini = $prev = $noche;
                continue;
            }

            $siguiente = (new DateTimeImmutable($prev, new DateTimeZone(self::TZ)))
                ->modify('+1 day')
                ->format('Y-m-d');

            if ($noche !== $siguiente) {
                $tramos[] = [$ini, $prev];
                $ini = $noche;
            }

            $prev = $noche;
        }

        if ($ini !== null && $prev !== null) {
            $tramos[] = [$ini, $prev];
        }

        return $tramos;
    }

    /**
     * Lee «-5», «+10», «-10%» o «15%».
     *
     * @return array{tipo: 'importe'|'porcentaje', valor: float}|null
     */
    private function interpretarAjuste(string $texto): ?array
    {
        $texto = str_replace([' ', ','], ['', '.'], $texto);

        if (!preg_match('/^([+-]?)(\d+(?:\.\d+)?)(%?)$/', $texto, $m)) {
            return null;
        }

        // Sin signo se entiende como subida: es lo que dice la descripción, y adivinar lo
        // contrario en una skill que publica precios sería caro.
        $signo = $m[1] === '-' ? -1.0 : 1.0;

        return [
            'tipo' => $m[3] === '%' ? 'porcentaje' : 'importe',
            'valor' => $signo * (float) $m[2],
        ];
    }

    /** @param array{tipo: string, valor: float} $ajuste */
    private function aplicar(float $precio, array $ajuste): float
    {
        return $ajuste['tipo'] === 'porcentaje'
            ? $precio * (1 + $ajuste['valor'] / 100)
            : $precio + $ajuste['valor'];
    }

    /** @return array<string, true> */
    private function nochesOcupadas(
        PmsUnidad $unidad,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta
    ): array {
        try {
            $ocupacion = $this->disponibilidad->ocupacion($desde, $hasta, (string) $unidad->getId());
        } catch (\Throwable) {
            return [];
        }

        $ocupadas = [];

        foreach ($ocupacion as $o) {
            $cursor = new DateTimeImmutable($o->entra, new DateTimeZone(self::TZ));
            $fin = new DateTimeImmutable($o->sale, new DateTimeZone(self::TZ));

            // La noche de salida no se ocupa: quien se va el 15 deja esa noche libre.
            while ($cursor < $fin) {
                // ⚠️ Acotado al tramo consultado. `ocupacion()` devuelve las reservas que
                // SOLAPAN, así que una que entró el 9 y sale el 18 traía también sus noches
                // del 9 al 14: el filtro seguía siendo correcto —se cruza por fecha— pero el
                // recuento decía «11 noches vendidas respetadas» en un tramo que sólo tiene 5.
                // Un número que no cuadra con lo que el operador ve en el calendario.
                if ($cursor >= $desde && $cursor < $hasta) {
                    $ocupadas[$cursor->format('Y-m-d')] = true;
                }

                $cursor = $cursor->modify('+1 day');
            }
        }

        return $ocupadas;
    }

    /** @param array<string, array{currency: ?string}> $afectadas */
    private function monedaDe(array $afectadas, PmsUnidad $unidad): string
    {
        foreach ($afectadas as $datos) {
            if (!empty($datos['currency'])) {
                return (string) $datos['currency'];
            }
        }

        return (string) ($unidad->getTarifaBaseMoneda()?->getId() ?? '');
    }

    /**
     * La entidad moneda para el rango nuevo: la del precio del que se parte.
     *
     * @param array<string, array{currency: ?string}> $afectadas
     */
    private function monedaEntidad(array $afectadas, PmsUnidad $unidad): ?MaestroMoneda
    {
        $id = $this->monedaDe($afectadas, $unidad);

        return $id !== ''
            ? $this->em->getRepository(\App\Entity\Maestro\MaestroMoneda::class)->find($id)
            : $unidad->getTarifaBaseMoneda();
    }

    private function resolverUnidad(string $pedida): ?PmsUnidad
    {
        $aguja = mb_strtolower($pedida);

        foreach ($this->em->getRepository(PmsUnidad::class)->findBy([], ['nombre' => 'ASC']) as $u) {
            if (str_contains(mb_strtolower((string) $u->getNombre()), $aguja)
                || str_contains(mb_strtolower((string) $u->getSlug()), $aguja)) {
                return $u;
            }
        }

        return null;
    }

    private function fecha(mixed $valor): ?DateTimeImmutable
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto, new DateTimeZone(self::TZ));

        return $fecha !== false ? $fecha : null;
    }
}
