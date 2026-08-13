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
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A cuánto sale una casita unas fechas concretas, noche a noche.
 *
 * El hueco que llenaba a mano el operador: hasta ahora el precio real sólo se podía ver
 * **creando la reserva**, porque `estimarAlojamiento()` vivía dentro de `crear_reserva`.
 * Preguntar «¿a cuánto está la 3 del 12 al 15?» obligaba a abrir el calendario de tarifas y
 * resolver de cabeza qué rango gana cada día.
 *
 * ### 🏆 El precio de una noche NO es la tarifa base
 *
 * Sobre un mismo día pueden solaparse varios `PmsTarifaRango`, y gana **uno**. El criterio lo
 * pone {@see \App\Pms\Service\Tarifa\Engine\TarifaDailyPriceFlattener}, en este orden:
 *
 * ```
 * 1. important = true            ← una promo marcada gana a todo
 * 2. prioridad (weight) mayor
 * 3. duración más corta          ← un rango de 3 días gana al de todo el mes
 * 4. id más reciente
 * ```
 *
 * Sólo si ningún rango cubre el día se cae a la tarifa base de la unidad. Por eso la respuesta
 * dice **de dónde sale** cada precio: sin eso, «85.00» no distingue una promo de fin de semana
 * de un hueco sin tarifa cargada, y son cosas muy distintas para quien vende.
 *
 * ### 🔁 Una fórmula, no dos
 *
 * Usa el MISMO `TarifaPricingEngine` que `PmsCargosAutomaticosService::estimarAlojamiento()` y
 * que el push de tarifas a Beds24. Si aquí se calculara aparte, el precio que se consulta y el
 * que se cobra podrían separarse — y el día que pasara, nadie lo vería hasta la factura.
 */
final readonly class ConsultarTarifasSkill implements SkillInterface, SkillDominioInterface
{
    private const string TZ = 'America/Lima';

    /** Techo del rango consultable. Un año de noches no cabe en una respuesta útil. */
    private const int MAX_NOCHES = 120;

    public function __construct(
        private EntityManagerInterface $em,
        private PmsTarifaCalculadora $tarifas,
        private PmsDisponibilidadService $disponibilidad,
    ) {}

    public function nombre(): string
    {
        return 'consultar_tarifas';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'A cuánto sale una casita en unas fechas, noche a noche y con el total. '
                . 'Úsala para «¿a cuánto está la 3 del 12 al 15?», «¿qué precio tengo puesto en '
                . 'agosto?». Devuelve el ALOJAMIENTO real de esas noches, no la '
                . 'tarifa base: cuando hay varios rangos sobre un día gana uno, y cada noche '
                . 'trae en «de_donde_sale» cuál ganó. SI UNA NOCHE DICE «tarifa base», es que no '
                . 'hay ninguna tarifa cargada para ese día: menciónalo, porque suele ser un '
                . '⚠️ EL TOTAL DE AQUÍ ES SOLO ALOJAMIENTO: no incluye limpieza ni el '
                . 'suplemento por persona adicional, así que NO es lo que se le cobra al '
                . 'huésped. Para cotizar de verdad —o si te preguntan «cuánto le cobro»— usa '
                . 'consultar_disponibilidad, que trae el desglose completo y el total. Esta '
                . 'skill es para revisar el TARIFARIO. '
                . 'olvido y no una decisión. Fíjate también en «estancia_minima»: vender menos '
                . 'noches que eso lo rechaza el canal. Sin casita, te da el precio de TODAS. Y '
                . 'si pides «solo_libres», descarta las noches ya vendidas, que es lo que sirve '
                . 'para decidir una promoción.',
            parametros: [
                SkillParameter::texto('desde', 'Primer día, en formato YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Último día (la noche de salida), YYYY-MM-DD.'),
                SkillParameter::texto('casita', 'Nombre o slug de la casita. Omítelo para ver '
                    . 'todas.', requerido: false),
                SkillParameter::booleano('solo_libres', 'true para descartar las noches que ya '
                    . 'están vendidas. Úsalo cuando la pregunta va de bajar precios o llenar '
                    . 'huecos: cambiar la tarifa de una noche ya vendida no sirve de nada.',
                    requerido: false),
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
        return [Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $desde = $this->fecha($entrada['desde'] ?? null);
        $hasta = $this->fecha($entrada['hasta'] ?? null);

        $faltan = [];

        if ($desde === null || $hasta === null) {
            $faltan[] = 'fechas';
        }

        if ($faltan !== []) {
            return SkillResult::ok([
                'falta_datos' => $faltan,
                'pregunta' => 'Pregúntale al operador qué fechas quiere mirar (desde y hasta, '
                    . 'en formato día/mes). Luego vuelve a llamarme con ellas.',
            ]);
        }

        if ($hasta <= $desde) {
            return SkillResult::error('La fecha final tiene que ser posterior a la inicial.');
        }

        $noches = (int) $desde->diff($hasta)->days;

        if ($noches > self::MAX_NOCHES) {
            return SkillResult::error(sprintf(
                'Son %d noches y el máximo de una consulta son %d. Pregunta por un tramo más corto.',
                $noches,
                self::MAX_NOCHES
            ));
        }

        $unidades = $this->resolverUnidades(trim((string) ($entrada['casita'] ?? '')));

        if ($unidades === []) {
            return SkillResult::error(
                'No encuentro esa casita. Dime su nombre tal como aparece en el panel, o no me '
                . 'digas ninguna y te doy todas.'
            );
        }

        $soloLibres = filter_var($entrada['solo_libres'] ?? false, FILTER_VALIDATE_BOOL);
        $resultado = [];

        foreach ($unidades as $unidad) {
            $resultado[] = $this->tarifaDe($unidad, $desde, $hasta, $soloLibres);
        }

        return SkillResult::ok([
            'desde' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
            'noches' => $noches,
            'solo_noches_libres' => $soloLibres,
            'casitas' => $resultado,
        ]);
    }

    /**
     * El precio noche a noche de UNA casita, con el rango que gana cada día.
     *
     * @return array<string, mixed>
     */
    private function tarifaDe(
        PmsUnidad $unidad,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        bool $soloLibres
    ): array {
        // 🔁 El cálculo vive en `PmsTarifaCalculadora`, no aquí: lo comparten esta skill,
        // `consultar_disponibilidad` y el cargo de alojamiento. Si cada una lo resolviera por
        // su cuenta, un día el precio que se enseña y el que se cobra dejarían de coincidir.
        $diarios = $this->tarifas->preciosPorNoche($unidad, $desde, $hasta);

        // Qué noches están vendidas. Se pregunta una sola vez por casita y se cruza por fecha:
        // preguntar noche a noche serían treinta consultas para un mes.
        $ocupadas = $soloLibres ? $this->nochesOcupadas($unidad, $desde, $hasta) : [];

        // Para poder nombrar el rango que ganó cada noche hace falta la entidad, no sólo su id.
        $nombresDeRango = [];
        foreach ($this->em->getRepository(PmsTarifaRango::class)->findBy(['unidad' => $unidad]) as $r) {
            $nombresDeRango[(string) $r->getId()] = $r;
        }

        $lineas = [];
        $total = 0.0;
        $moneda = null;
        $minStay = 0;
        $sinTarifa = 0;

        foreach ($diarios as $dia => $datos) {
            if ($soloLibres && isset($ocupadas[$dia])) {
                continue;
            }

            $precio = (float) $datos['price'];
            $total += $precio;
            $moneda ??= $datos['currency'];
            $minStay = max($minStay, (int) $datos['minStay']);

            $origen = $this->origenDe((string) $datos['sourceId'], $nombresDeRango);

            if ($origen === 'tarifa base') {
                ++$sinTarifa;
            }

            $lineas[] = [
                'noche' => $dia,
                'precio' => sprintf('%.2f', $precio),
                'de_donde_sale' => $origen,
            ];
        }

        return array_filter([
            'casita' => $unidad->getNombre(),
            'noches_contadas' => count($lineas),
            'total' => $lineas !== [] ? sprintf('%.2f %s', $total, $moneda ?? '') : null,
            'estancia_minima' => $minStay > 0 ? $minStay : null,
            'por_noche' => $lineas !== [] ? $lineas : null,
            'noches_vendidas_omitidas' => $soloLibres && $ocupadas !== [] ? count($ocupadas) : null,
            // Se cuenta aparte porque es lo que el operador querría saber sin leer línea a
            // línea: cuántas noches se están vendiendo al precio de reserva, sin tarifa propia.
            'aviso_sin_tarifa' => $sinTarifa > 0
                ? sprintf(
                    '%d de esas noches NO tienen tarifa cargada y salen a la tarifa base de la '
                    . 'casita. Suele ser un olvido: díselo al operador.',
                    $sinTarifa
                )
                : null,
            'sin_precio' => $lineas === []
                ? ($soloLibres
                    ? 'Todas esas noches ya están vendidas.'
                    : 'No hay tarifa cargada ni tarifa base activa para esas fechas.')
                : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * De qué rango salió el precio de una noche, en palabras.
     *
     * El formato del `sourceId` lo conoce `PmsTarifaCalculadora`, no esta skill: viene
     * prefijado (`id:<uuid>`, `base:unidad:<uuid>`) y buscarlo por el uuid pelado no encuentra
     * nada — todas las noches acababan diciendo «tarifa base», incluidas las que sí tenían su
     * rango.
     *
     * @param array<string, PmsTarifaRango> $rangos
     */
    private function origenDe(string $sourceId, array $rangos): string
    {
        $uuid = PmsTarifaCalculadora::rangoDe($sourceId);

        if ($uuid === null) {
            return 'tarifa base';
        }

        $rango = $rangos[$uuid] ?? null;

        if ($rango === null) {
            return 'tarifa base';
        }

        return sprintf(
            'rango %s→%s%s%s',
            $rango->getFechaInicio()?->format('d/m') ?? '?',
            $rango->getFechaFin()?->format('d/m') ?? '?',
            $rango->isImportante() ? ' (destacado)' : '',
            $rango->getPrioridad() > 0 ? sprintf(' prioridad %d', $rango->getPrioridad()) : ''
        );
    }

    /**
     * Las noches ya vendidas, indexadas por fecha para cruzarlas en O(1).
     *
     * @return array<string, true>
     */
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

            // Se marca la noche de ENTRADA y no la de salida: quien se va el día 15 no ocupa
            // esa noche, y marcarla escondería una noche vendible.
            while ($cursor < $fin) {
                $ocupadas[$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $ocupadas;
    }

    /**
     * @return list<PmsUnidad>
     */
    private function resolverUnidades(string $pedida): array
    {
        $todas = $this->em->getRepository(PmsUnidad::class)->findBy([], ['nombre' => 'ASC']);

        if ($pedida === '') {
            return $todas;
        }

        $aguja = mb_strtolower($pedida);

        return array_values(array_filter(
            $todas,
            static fn (PmsUnidad $u): bool => str_contains(mb_strtolower((string) $u->getNombre()), $aguja)
                || str_contains(mb_strtolower((string) $u->getSlug()), $aguja)
        ));
    }

    /** Fecha estricta: lo que no sea YYYY-MM-DD cae a `null` y se pregunta. */
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
