<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Service\Finance\PmsCargosAutomaticosService;
use App\Pms\Service\Reserva\PmsEstanciaCreator;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Añade una estancia a una reserva que YA existe. **Escribe y sale al canal.**
 *
 * Es la otra mitad de {@see CrearReservaSkill}, y son dos skills distintas porque responden a
 * dos peticiones distintas del operador:
 *
 * - «reserva a Ana del 10 al 13» → no hay huésped todavía: `crear_reserva`.
 * - «a Ana añádele la casita 2» / «se queda dos noches más en otra casita» → el huésped ya está
 *   en el sistema con su nombre, idioma y teléfono: **esto**.
 *
 * Fundirlas habría obligado a decidir en ejecución si el `reserva_id` que llega significa
 * «añade aquí» o «ignóralo y crea otra», y equivocarse en esa lectura crea una reserva duplicada
 * del mismo huésped.
 *
 * ### Casos reales que cubre
 *
 * 1. **Grupo que ocupa varias casitas** con las mismas fechas: una reserva, N estancias.
 * 2. **Cambio de casita a mitad**: dos tramos consecutivos en unidades distintas.
 * 3. **Alarga la estancia en otra casita** porque la suya está vendida esas noches.
 *
 * Los tres existen en el parque y hasta ahora sólo se podían hacer desde el calendario.
 *
 * ### Lo difícil no está aquí
 *
 * Horas del establecimiento, disponibilidad, estados maestros y el canal DIRECTO los resuelve
 * {@see PmsEstanciaCreator}, compartido con `crear_reserva`. Repetirlo aquí era garantizar que
 * las dos copias se separaran —basta que una olvide el `setChannel()` y la estancia nace sin
 * cargos, en silencio—.
 *
 * ### ⚠️ Añadir una estancia mueve las fechas de la RESERVA
 *
 * `fechaLlegada` y `fechaSalida` son agregados (mín/máx de sus tramos), así que una estancia
 * nueva puede correrlas — y con ellas los hitos del motor de reglas, o sea **cuándo le llegan
 * los mensajes al huésped**. Está en la previsualización porque no es evidente.
 */
final readonly class CrearEstanciaSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsEstanciaCreator $estancias,
        private PmsCargosAutomaticosService $cargos,
    ) {}

    public function nombre(): string
    {
        return 'crear_estancia';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Añade OTRA estancia (otra casita, u otro tramo de fechas) a una reserva '
                . 'que ya existe. Úsala cuando el huésped ya está en el sistema: «al grupo de Ana '
                . 'súmale la casita 2», «se cambia a la casita 5 las dos últimas noches», «se '
                . 'queda dos noches más». Para un huésped NUEVO usa crear_reserva, no ésta: si '
                . 'creas otra reserva a alguien que ya la tiene, acabas con dos reservas del '
                . 'mismo huésped y nadie sabe cuál es la buena. Necesita el reserva_id de '
                . 'buscar_reserva. MODIFICA DATOS Y SALE FUERA: crea la estancia, le genera sus '
                . 'cargos y manda el bloqueo a Beds24 —esa casita deja de venderse esas noches en '
                . 'todos los portales—. Llama SIEMPRE primero con confirmado=false y enséñale al '
                . 'operador «que_va_a_pasar» entero. Comprueba la disponibilidad ella sola: si la '
                . 'casita está ocupada te lo dice y no crea nada.',
            parametros: [
                SkillParameter::texto('reserva_id', 'La reserva a la que se le añade, de '
                    . 'buscar_reserva.'),
                SkillParameter::texto('casita', 'Nombre de la casita, «Casita 2» o sólo «2».'),
                SkillParameter::texto('desde', 'Fecha de entrada de ESTE tramo, YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Fecha de salida de ESTE tramo, YYYY-MM-DD.'),
                SkillParameter::entero('adultos', 'Cuántos adultos en esta casita. Si no lo dicen, '
                    . 'pregúntalo: en un grupo repartido no tiene por qué ser el mismo número que '
                    . 'en la otra.', requerido: false),
                SkillParameter::entero('ninos', 'Cuántos niños en esta casita. 0 si no hay.',
                    requerido: false),
                SkillParameter::texto('estado', '"pendiente" (por defecto) o "confirmada".',
                    requerido: false),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación explícita '
                    . 'del operador. false para previsualizar.'),
            ],
        );
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
        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'Necesito el reserva_id. Localiza la reserva con buscar_reserva; si el huésped '
                . 'es nuevo, lo que toca es crear_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        // Una reserva cancelada conserva nombre, fechas y chat: nada la delata salvo el estado
        // de sus tramos. Añadirle una estancia la resucitaría a medias.
        if ($reserva->isCancelada()) {
            return SkillResult::error(sprintf(
                'La reserva de %s está CANCELADA. No se le pueden añadir estancias: si el '
                . 'huésped vuelve, crea una reserva nueva.',
                $reserva->getNombreApellido() ?? 'ese huésped'
            ));
        }

        $desde = $this->fecha($entrada['desde'] ?? null);
        $hasta = $this->fecha($entrada['hasta'] ?? null);

        if ($desde === null || $hasta === null) {
            return SkillResult::error('Indica las fechas de entrada y salida en formato YYYY-MM-DD.');
        }

        if ($hasta <= $desde) {
            return SkillResult::error('La fecha de salida tiene que ser posterior a la de entrada.');
        }

        $unidad = $this->resolverCasita(trim((string) ($entrada['casita'] ?? '')));

        if (is_string($unidad)) {
            return SkillResult::error($unidad);
        }

        // La disponibilidad ANTES de pedir nada: si está ocupada, preguntar cuántos adultos
        // sería hacerle perder el tiempo al operador.
        $motivo = $this->estancias->motivoNoDisponible($unidad, $desde, $hasta);

        if ($motivo !== null) {
            return SkillResult::error(
                $motivo . ' Busca otra casita con consultar_disponibilidad o cambia las fechas.'
            );
        }

        $adultos = (int) ($entrada['adultos'] ?? 0);

        if ($adultos < 1) {
            return SkillResult::ok([
                'reserva_id' => $reservaId,
                'huesped' => $reserva->getNombreApellido(),
                'casita' => $unidad->getNombre(),
                'desde' => $desde->format('Y-m-d'),
                'hasta' => $hasta->format('Y-m-d'),
                'disponible' => true,
                'falta_datos' => ['adultos'],
                'pregunta' => sprintf(
                    'Pregúntale al operador cuántos adultos van en %s. En un grupo repartido en '
                    . 'varias casitas no tiene por qué ser el mismo número que en las otras, así '
                    . 'que no lo deduzcas de la reserva.',
                    $unidad->getNombre()
                ),
            ]);
        }

        return $this->conDatosCompletos($entrada, $reserva, $unidad, $desde, $hasta, $adultos);
    }

    private function conDatosCompletos(
        array $entrada,
        PmsReserva $reserva,
        PmsUnidad $unidad,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        int $adultos
    ): SkillResult {
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);
        $ninos = max(0, (int) ($entrada['ninos'] ?? 0));

        $estadoId = strtolower(trim((string) ($entrada['estado'] ?? PmsEventoEstado::CODIGO_PENDIENTE)));
        if (!in_array($estadoId, [PmsEventoEstado::CODIGO_PENDIENTE, PmsEventoEstado::CODIGO_CONFIRMADA], true)) {
            $estadoId = PmsEventoEstado::CODIGO_PENDIENTE;
        }

        $horario = $this->estancias->conHorario($unidad, $desde, $hasta);
        $alojamiento = $this->cargos->estimarAlojamiento($unidad, $horario['entrada'], $horario['salida']) ?? 0.0;
        $limpieza = (float) PmsCargosAutomaticosService::TARIFA_LIMPIEZA;

        $resumen = array_filter([
            'reserva_id' => (string) $reserva->getId(),
            'huesped' => $reserva->getNombreApellido(),
            'localizador' => $reserva->getLocalizador(),
            'casita' => $unidad->getNombre(),
            'entrada' => $horario['entrada']->format('Y-m-d H:i'),
            'salida' => $horario['salida']->format('Y-m-d H:i'),
            'noches' => $desde->diff($hasta)->days,
            'adultos' => $adultos,
            'ninos' => $ninos ?: null,
            'estado' => $estadoId,
            'estancias_actuales' => $this->estanciasActuales($reserva),
            'cargos_previstos' => [
                [
                    'concepto' => 'Alojamiento',
                    'importe' => sprintf('%.2f', $alojamiento),
                    'origen' => $alojamiento > 0
                        ? 'tarifario, noche a noche'
                        : '⚠️ el tarifario no cubre estas noches: quedará en 0.00 y habrá que ponerlo a mano',
                ],
                [
                    'concepto' => 'Suplemento de limpieza',
                    'importe' => sprintf('%.2f', $limpieza),
                    'origen' => 'tarifa fija',
                ],
            ],
            'total_previsto' => sprintf(
                '%.2f %s',
                $alojamiento + $limpieza,
                $unidad->getTarifaBaseMonedaId() ?? 'USD'
            ),
        ], static fn ($v) => $v !== null);

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'creada' => false,
                'motivo' => 'falta_confirmacion',
                'que_va_a_pasar' => $this->consecuencias($reserva, $unidad, $desde, $hasta),
                'pregunta_aprobacion' => '¿Apruebas añadir la estancia?',
                'previsualizacion' => sprintf(
                    'Se añadirá a la reserva de %s (%s) una estancia en %s del %s al %s. '
                    . 'Enséñale al operador los cargos y «que_va_a_pasar» entero antes del sí.',
                    $resumen['huesped'] ?? 'el huésped',
                    $resumen['localizador'] ?? 'sin localizador',
                    $unidad->getNombre(),
                    $desde->format('Y-m-d'),
                    $hasta->format('Y-m-d')
                ),
            ]);
        }

        try {
            $evento = $this->estancias->crear($reserva, $unidad, $desde, $hasta, $adultos, $ninos, $estadoId);
        } catch (InvalidArgumentException $e) {
            // La disponibilidad se comprobó arriba, pero entre aquella consulta y este flush
            // pudo entrar otra reserva. El creador la vuelve a mirar y aquí se traduce.
            return SkillResult::error($e->getMessage());
        }

        $this->em->flush();

        return SkillResult::ok($resumen + [
            'creada' => true,
            'evento_id' => (string) $evento->getId(),
            'que_ha_pasado' => $this->consecuencias($reserva, $unidad, $desde, $hasta),
        ]);
    }

    /**
     * Lo que ya tiene la reserva, para que el operador vea dónde encaja lo nuevo.
     *
     * Es lo que evita el error más caro de esta skill: añadir una casita a quien ya la tenía
     * porque nadie miró la lista.
     *
     * @return list<string>
     */
    private function estanciasActuales(PmsReserva $reserva): array
    {
        $lista = [];

        foreach ($reserva->getEventosCalendario() as $evento) {
            if ($evento->getEventoOrigen() !== null) {
                continue;
            }

            $lista[] = sprintf(
                '%s: %s → %s (%s)',
                $evento->getPmsUnidad()?->getNombre() ?? '—',
                $evento->getInicio()?->format('Y-m-d') ?? '?',
                $evento->getFin()?->format('Y-m-d') ?? '?',
                $evento->getEstado()?->getId() ?? '?'
            );
        }

        return $lista;
    }

    /**
     * @return list<string>
     */
    private function consecuencias(
        PmsReserva $reserva,
        PmsUnidad $unidad,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta
    ): array {
        $casita = $unidad->getNombre() ?? 'la casita';

        $lista = [
            sprintf(
                'Se añade la estancia a la reserva %s, que pasará a tener %d.',
                $reserva->getLocalizador() ?? '',
                count($this->estanciasActuales($reserva)) + 1
            ),
            sprintf(
                'Se le generan sus propios cargos (alojamiento y limpieza) en la MISMA cuenta '
                . 'de la reserva: el saldo del huésped sube.'
            ),
            sprintf(
                '%s SALE DE LA VENTA del %s al %s en Beds24 y, por tanto, en todos los portales. '
                . 'No es instantáneo: viaja en la cola de push.',
                $casita,
                $desde->format('Y-m-d'),
                $hasta->format('Y-m-d')
            ),
        ];

        // Sólo si de verdad se mueven: si el tramo cae dentro de las fechas que ya tenía, los
        // agregados no cambian y decirlo sería ruido.
        $llegada = $reserva->getFechaLlegada();
        $salida = $reserva->getFechaSalida();

        if (($llegada !== null && $desde < $llegada) || ($salida !== null && $hasta > $salida)) {
            $lista[] = 'Las fechas de la RESERVA se recalculan (son el mínimo y el máximo de sus '
                . 'estancias), así que pueden moverse los hitos del motor de reglas: CUÁNDO le '
                . 'llegan al huésped la guía de llegada y el aviso de salida.';
        }

        return $lista;
    }

    /** @return PmsUnidad|string La unidad, o el mensaje de error. */
    private function resolverCasita(string $busqueda): PmsUnidad|string
    {
        if ($busqueda === '') {
            return 'Indica en qué casita.';
        }

        $qb = $this->em->getRepository(PmsUnidad::class)->createQueryBuilder('u')
            ->where('u.activo = true')
            ->orderBy('u.nombre', 'ASC');

        // Un número suelto es «Casita 2», no cualquier nombre que contenga un 2.
        if (preg_match('/^\d+$/', $busqueda) === 1) {
            $qb->andWhere('u.nombre LIKE :s')->setParameter('s', '% ' . $busqueda);
        } else {
            $qb->andWhere('LOWER(u.nombre) LIKE :l')->setParameter('l', '%' . mb_strtolower($busqueda) . '%');
        }

        $encontradas = $qb->getQuery()->getResult();

        if ($encontradas === []) {
            return sprintf('No hay ninguna casita que se llame «%s».', $busqueda);
        }

        if (count($encontradas) > 1) {
            return sprintf(
                '«%s» encaja con varias casitas: %s. Pregunta al operador cuál.',
                $busqueda,
                implode(', ', array_map(static fn (PmsUnidad $u) => (string) $u->getNombre(), $encontradas))
            );
        }

        return $encontradas[0];
    }

    private function fecha(mixed $valor): ?DateTimeImmutable
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return $fecha !== false ? $fecha : null;
    }
}
