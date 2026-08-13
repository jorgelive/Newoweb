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
use App\Entity\Maestro\MaestroIdioma;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Finance\PmsCargosAutomaticosService;
use App\Pms\Service\Reserva\PmsDisponibilidadService;
use App\Pms\Service\Reserva\PmsEstanciaCreator;
use App\Security\Roles;
use DateTimeImmutable;
use InvalidArgumentException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crea una reserva directa. **Es la skill que más cosas dispara del catálogo.**
 *
 * Un `INSERT` aquí desencadena, sin que esta clase orqueste nada de ello:
 *
 * 1. La cabecera financiera (`PmsInformacionFinancieraCoherenciaListener`, auto-provisión).
 * 2. Los cargos del tarifario y la limpieza (`PmsCargosAutomaticosService`).
 * 3. **Un push a Beds24** (`Beds24BookingsPushQueueListener`), o sea que la casita deja de
 *    venderse esas noches en todos los portales.
 * 4. Los mensajes programados del motor de reglas: `recordatorio_llegada` para el día de
 *    entrada y `check_out` para el de salida — **mensajes reales a una persona**.
 *
 * Por eso la previsualización enumera las cuatro. Aprobar «se creará una reserva» sin saber que
 * sale al canal y que se programan mensajes no es aprobar lo que de verdad pasa.
 *
 * ### Los datos que faltan se preguntan JUNTOS
 *
 * «Crea una reserva en la casita 1 del 12 al 15» no trae ni la mitad de lo obligatorio. En vez
 * de fallar por el primero que falte —y hacer que el operador conteste de uno en uno—, se
 * reúnen todos y se devuelve una sola pregunta. Mismo patrón que {@see RegistrarPagoSkill}.
 *
 * Se distinguen dos niveles, y la diferencia importa:
 *
 * - **Obligatorios**: sin ellos no se puede crear (nombre, idioma, adultos).
 * - **Recomendados**: el teléfono. No lo exige la base, pero sin él no hay WhatsApp, ni guía
 *   que enviar, ni recordatorio que llegue. Se pregunta y se puede seguir sin él.
 *
 * ### Las horas salen del establecimiento, no de una constante
 *
 * `PmsEstablecimiento::getHoraCheckIn()` y `getHoraCheckOut()`. Codificar 14:00/10:00 aquí
 * habría creado una segunda verdad que se desincroniza el día que un establecimiento cambie.
 *
 * ### Los tres desvíos del cálculo automático
 *
 * Por defecto el sistema pone alojamiento del tarifario, limpieza fija y **exonera servicio**
 * (§ `PmsCargosAutomaticosService`). Esta skill deja apartarse de eso porque es lo que se
 * negocia al vender: un precio cerrado, perdonar la limpieza, o cobrar un % de servicio.
 */
final readonly class CrearReservaSkill implements SkillInterface, SkillDominioInterface
{
    /** Los que hablamos. Un idioma fuera de esto no tiene plantillas traducidas. */
    private const array IDIOMAS = ['es', 'en', 'pt', 'fr', 'it', 'de', 'nl'];

    public function __construct(
        private EntityManagerInterface $em,
        private PmsDisponibilidadService $disponibilidad,
        private PmsCargosAutomaticosService $cargos,
        private PmsEstanciaCreator $estancias,
    ) {}

    public function nombre(): string
    {
        return 'crear_reserva';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Crea una reserva DIRECTA (no de una OTA) en una casita y unas fechas. '
                . 'MODIFICA DATOS Y SALE FUERA: crea la reserva, sus cargos, la manda a Beds24 '
                . '—la casita deja de venderse esas noches en todos los portales— y programa '
                . 'los mensajes automáticos al huésped. Llama SIEMPRE primero con '
                . 'confirmado=false: la respuesta trae «que_va_a_pasar» con las consecuencias y '
                . '«cargos_previstos» con el dinero. Enséñaselas al operador enteras y espera su '
                . 'sí. Si faltan datos devuelve «falta_datos» con una pregunta: trasládala tal '
                . 'cual y vuelve a llamarme con lo que conteste, sin inventar nada — NUNCA te '
                . 'inventes el nombre del huésped, el idioma ni el número de personas. Comprueba '
                . 'la disponibilidad ella sola: si está ocupada te lo dice y no crea nada.',
            parametros: [
                SkillParameter::texto('casita', 'Nombre de la casita, «Casita 1» o sólo «1».'),
                SkillParameter::texto('desde', 'Fecha de entrada, YYYY-MM-DD.'),
                SkillParameter::texto('hasta', 'Fecha de salida, YYYY-MM-DD. Es el día que se '
                    . 'va: del 12 al 15 son 3 noches.'),
                SkillParameter::texto('nombre', 'Nombre del huésped. OBLIGATORIO.', requerido: false),
                SkillParameter::texto('apellido', 'Apellido del huésped.', requerido: false),
                SkillParameter::texto('idioma', 'Idioma del huésped: ' . implode(', ', self::IDIOMAS)
                    . '. OBLIGATORIO: decide en qué lengua le llegan los mensajes.', requerido: false),
                SkillParameter::entero('adultos', 'Cuántos adultos. OBLIGATORIO.', requerido: false),
                SkillParameter::entero('ninos', 'Cuántos niños. 0 si no hay.', requerido: false),
                SkillParameter::texto('telefono', 'Teléfono con prefijo internacional. No es '
                    . 'obligatorio, pero sin él no hay WhatsApp ni le llegan la guía ni los '
                    . 'recordatorios: pregúntalo siempre.', requerido: false),
                SkillParameter::texto('email', 'Correo del huésped.', requerido: false),
                SkillParameter::texto('estado', '"pendiente" (por defecto) o "confirmada".',
                    requerido: false),
                SkillParameter::texto('precio_final', 'Precio cerrado del alojamiento, si se ha '
                    . 'negociado uno. Si se omite, se cobra lo que diga el tarifario día a día.',
                    requerido: false),
                SkillParameter::texto('cobrar_limpieza', '"si" (por defecto) o "no" para '
                    . 'perdonar el suplemento de limpieza.', requerido: false),
                SkillParameter::texto('servicio_porcentaje', 'Porcentaje de cargo por servicio, '
                    . 'si se cobra. Por defecto NO se cobra en reservas directas.', requerido: false),
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
        $unidad = $this->resolverCasita(trim((string) ($entrada['casita'] ?? '')));

        // Una salida anterior a la entrada NO es un dato que falte: es uno que está mal, y
        // preguntarlo junto a otros tres sería enterrar el aviso.
        if ($desde !== null && $hasta !== null && $hasta <= $desde) {
            return SkillResult::error('La fecha de salida tiene que ser posterior a la de entrada.');
        }

        // ⚠️ Faltar las fechas o la casita ya no corta con un error suelto: se juntan con el
        // nombre, el idioma y los adultos en UNA pregunta. «Créame una reserva para Ana» hacía
        // tres viajes al operador —primero las fechas, luego la casita, luego lo demás—.
        //
        // Lo que NO se toca es el orden: sin fechas y sin casita no se puede mirar la
        // disponibilidad, así que en ese caso se pregunta todo de golpe y se comprueba en la
        // vuelta siguiente. Cuando SÍ hay fechas y casita, la disponibilidad se sigue mirando
        // antes de pedir datos del huésped — si está ocupada, esos datos no hacen falta.
        $faltan = [];
        $preguntas = [];

        if ($desde === null || $hasta === null) {
            $faltan[] = 'fechas';
            $preguntas[] = '¿qué días? (entrada y salida)';
        }

        if (is_string($unidad)) {
            $faltan[] = 'casita';
            $preguntas[] = $unidad;
        }

        $nombre = trim((string) ($entrada['nombre'] ?? ''));
        $idioma = strtolower(trim((string) ($entrada['idioma'] ?? '')));
        $adultos = (int) ($entrada['adultos'] ?? 0);
        $telefono = trim((string) ($entrada['telefono'] ?? ''));

        if ($nombre === '') {
            $faltan[] = 'nombre';
            $preguntas[] = '¿a nombre de quién?';
        }

        if (!in_array($idioma, self::IDIOMAS, true)) {
            $faltan[] = 'idioma';
            $preguntas[] = sprintf('¿en qué idioma le escribimos? (%s)', implode(', ', self::IDIOMAS));
        }

        if ($adultos < 1) {
            $faltan[] = 'adultos';
            $preguntas[] = '¿cuántos adultos?';
        }

        // El teléfono no lo exige la base, pero sin él la reserva nace muda. Se pregunta con
        // el resto y NO bloquea: se puede crear y añadirlo luego.
        $recomendados = [];

        if ($telefono === '') {
            $recomendados[] = 'telefono';
            $preguntas[] = '¿tienes su teléfono con prefijo? sin él no hay WhatsApp ni le llegan '
                . 'la guía ni los recordatorios';
        }

        // 🗓️ La disponibilidad, en cuanto se puede: con fechas y casita ya se sabe si tiene
        // sentido seguir. Si está ocupada se corta AQUÍ aunque falten datos del huésped —
        // pedirle el nombre y el idioma para una casita que no se puede vender es hacerle
        // perder el tiempo—.
        $sePuedeMirar = $desde !== null && $hasta !== null && !is_string($unidad);

        if ($sePuedeMirar) {
            $ocupacion = $this->disponibilidad->ocupacion($desde, $hasta, (string) $unidad->getId());

            if ($ocupacion !== []) {
                return SkillResult::error(sprintf(
                    '%s NO está libre del %s al %s: %s. Busca otra casita con '
                    . 'consultar_disponibilidad o cambia las fechas.',
                    $unidad->getNombre(),
                    $desde->format('Y-m-d'),
                    $hasta->format('Y-m-d'),
                    implode('; ', array_map(
                        static fn ($o) => sprintf('%s del %s al %s', $o->huesped ?? 'bloqueo', $o->entra, $o->sale),
                        $ocupacion
                    ))
                ));
            }
        }

        if ($faltan !== [] || $recomendados !== []) {
            return SkillResult::ok(array_filter([
                // Los datos de la estancia sólo si se saben: con las fechas entre lo que falta,
                // inventar aquí un `desde` sería justo lo que la pregunta trata de evitar.
                'casita' => $sePuedeMirar ? $unidad->getNombre() : null,
                'desde' => $desde?->format('Y-m-d'),
                'hasta' => $hasta?->format('Y-m-d'),
                'noches' => $desde !== null && $hasta !== null ? $desde->diff($hasta)->days : null,
                'disponible' => $sePuedeMirar ? true : null,
                'falta_datos' => $faltan !== [] ? $faltan : null,
                'conviene_preguntar' => $recomendados !== [] ? $recomendados : null,
                'pregunta' => 'Pregúntale al operador, todo de una vez: ' . implode('; y ', $preguntas)
                    . '. Luego vuelve a llamarme con lo que conteste.',
                // Sin los obligatorios no se puede crear; sin el teléfono sí, y el operador
                // tiene que poder decir «no lo tengo, créala igual».
                'se_puede_crear_sin_esto' => $faltan === [] ? true : null,
            ], static fn ($v) => $v !== null));
        }

        // Con `$faltan` vacío, las fechas y la casita están resueltas por construcción: si no,
        // estarían en la lista. Explícito para que `conDatosCompletos()` reciba sus tipos sin
        // que haya que rehacer ese razonamiento al leer.
        if (is_string($unidad) || $desde === null || $hasta === null) {
            return SkillResult::error('Faltan las fechas o la casita. Pregúntaselas al operador.');
        }

        return $this->conDatosCompletos($entrada, $unidad, $desde, $hasta, $actor);
    }

    /**
     * @return SkillResult
     */
    private function conDatosCompletos(
        array $entrada,
        PmsUnidad $unidad,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        ActorInterface $actor
    ): SkillResult {
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);
        $establecimiento = $unidad->getEstablecimiento();

        if ($establecimiento === null) {
            return SkillResult::error('Esta casita no tiene establecimiento asignado.');
        }

        $idiomaEnt = $this->em->getRepository(MaestroIdioma::class)
            ->find(strtolower(trim((string) $entrada['idioma'])));

        if ($idiomaEnt === null) {
            return SkillResult::error('Ese idioma no está dado de alta en el maestro.');
        }

        $estadoId = strtolower(trim((string) ($entrada['estado'] ?? 'pendiente')));
        if (!in_array($estadoId, ['pendiente', 'confirmada'], true)) {
            $estadoId = 'pendiente';
        }

        $adultos = max(1, (int) $entrada['adultos']);
        $ninos = max(0, (int) ($entrada['ninos'] ?? 0));

        // Las horas del establecimiento, no una constante: ver el docblock de la clase.
        $horaIn = $establecimiento->getHoraCheckIn()?->format('H:i') ?? '14:00';
        $horaOut = $establecimiento->getHoraCheckOut()?->format('H:i') ?? '10:00';

        [$hIn, $mIn] = array_map('intval', explode(':', $horaIn));
        [$hOut, $mOut] = array_map('intval', explode(':', $horaOut));

        $inicio = $desde->setTime($hIn, $mIn);
        $fin = $hasta->setTime($hOut, $mOut);

        $cargos = $this->cargosPrevistos($entrada, $unidad, $inicio, $fin, $adultos + $ninos);

        $resumen = array_filter([
            'casita' => $unidad->getNombre(),
            'establecimiento' => $establecimiento->getNombreComercial(),
            'huesped' => trim($entrada['nombre'] . ' ' . ($entrada['apellido'] ?? '')),
            'idioma' => $idiomaEnt->getId(),
            'entrada' => $inicio->format('Y-m-d H:i'),
            'salida' => $fin->format('Y-m-d H:i'),
            'noches' => $desde->diff($hasta)->days,
            'adultos' => $adultos,
            'ninos' => $ninos ?: null,
            'telefono' => trim((string) ($entrada['telefono'] ?? '')) ?: null,
            'email' => trim((string) ($entrada['email'] ?? '')) ?: null,
            'estado' => $estadoId,
            'cargos_previstos' => $cargos['lineas'],
            'total_previsto' => sprintf('%.2f %s', $cargos['total'], $cargos['moneda']),
        ], static fn ($v) => $v !== null);

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'creada' => false,
                'motivo' => 'falta_confirmacion',
                'que_va_a_pasar' => $this->consecuencias($unidad, $desde, $hasta, $resumen),
                'pregunta_aprobacion' => '¿Apruebas crear la reserva?',
                'previsualizacion' => sprintf(
                    'Se creará una reserva para %s en %s, del %s al %s (%d noches). Enséñale al '
                    . 'operador los cargos y la lista de «que_va_a_pasar» entera antes de pedirle el sí.',
                    $resumen['huesped'],
                    $unidad->getNombre(),
                    $desde->format('Y-m-d'),
                    $hasta->format('Y-m-d'),
                    $resumen['noches']
                ),
            ]);
        }

        return $this->crear($entrada, $unidad, $inicio, $fin, $idiomaEnt, $estadoId, $adultos, $ninos, $resumen, $actor);
    }

    /**
     * Lo que va a costar, calculado ANTES de crear nada.
     *
     * El alojamiento sale de `PmsCargosAutomaticosService::estimarAlojamiento()` — el mismo
     * método que luego cobra—, salvo que el operador haya cerrado un precio.
     *
     * @return array{lineas: list<array<string,mixed>>, total: float, moneda: string}
     */
    private function cargosPrevistos(
        array $entrada,
        PmsUnidad $unidad,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        int $pax = 0
    ): array {
        $moneda = $unidad->getTarifaBaseMonedaId() ?? 'USD';
        $lineas = [];

        $precioFinal = trim((string) ($entrada['precio_final'] ?? ''));

        if ($precioFinal !== '') {
            $alojamiento = (float) str_replace(',', '.', $precioFinal);
            $lineas[] = [
                'concepto' => 'Alojamiento',
                'importe' => sprintf('%.2f', $alojamiento),
                'origen' => 'precio cerrado por el operador (NO se usa el tarifario)',
            ];
        } else {
            $alojamiento = $this->cargos->estimarAlojamiento($unidad, $inicio, $fin) ?? 0.0;
            $lineas[] = [
                'concepto' => 'Alojamiento',
                'importe' => sprintf('%.2f', $alojamiento),
                'origen' => $alojamiento > 0
                    ? 'tarifario, noche a noche'
                    : '⚠️ el tarifario no cubre todas las noches: quedará en 0.00 y habrá que ponerlo a mano',
            ];
        }

        // 👥 EL SUPLEMENTO POR PERSONA, que SÍ se cobra y aquí no se enseñaba.
        //
        // `PmsCargosAutomaticosService::generarParaEvento()` crea este cargo, así que la
        // previsualización que el operador aprueba salía por debajo de lo que luego aparecía
        // en la cuenta. Con 7 personas donde la tarifa cubre 5 y 3 noches, eran 36.00 que
        // nadie había visto antes de confirmar.
        // ⚠️ A DÍA, no con las horas dentro. La estancia va de las 14:00 a las 10:00 y un
        // `diff()` crudo de dos noches devuelve «1 día y 20 horas» → 1. Es la misma trampa que
        // documenta `PmsCargosAutomaticosService`, y caer en ella aquí cotizaba la mitad del
        // suplemento que luego se cobra: 18.00 donde eran 36.00.
        $noches = (int) $inicio->setTime(0, 0)->diff($fin->setTime(0, 0))->days;
        $suplemento = $unidad->suplementoPorPax($pax, $noches);

        if ($suplemento > 0.0) {
            $lineas[] = [
                'concepto' => sprintf(
                    'Persona adicional (%d × %s por noche)',
                    max(0, $pax - $unidad->getPaxIncluidos()),
                    $unidad->getPrecioPaxAdicional()
                ),
                'importe' => sprintf('%.2f', $suplemento),
                'origen' => 'regla de la unidad',
            ];
        }

        $total = $alojamiento + $suplemento;

        if (strtolower(trim((string) ($entrada['cobrar_limpieza'] ?? 'si'))) !== 'no') {
            // 🧹 De la unidad, no de una constante: puede ser importe fijo o porcentaje. La
            // base del porcentaje es alojamiento + suplemento, y la decide `costoLimpieza()`.
            $limpieza = $unidad->costoLimpieza($noches, $total);

            if ($limpieza > 0.0) {
                $lineas[] = [
                    'concepto' => 'Suplemento de limpieza',
                    'importe' => sprintf('%.2f', $limpieza),
                    'origen' => $unidad->limpiezaEsPorcentaje()
                        ? sprintf('%s%% sobre alojamiento + suplemento', rtrim(rtrim($unidad->getPrecioLimpieza(), '0'), '.'))
                        : 'importe fijo de la unidad',
                ];
                $total += $limpieza;
            }
        } else {
            $lineas[] = [
                'concepto' => 'Suplemento de limpieza',
                'importe' => '0.00',
                'origen' => 'PERDONADO por el operador',
            ];
        }

        $pct = (float) str_replace(',', '.', (string) ($entrada['servicio_porcentaje'] ?? '0'));

        if ($pct > 0) {
            // ⚠️ Sobre alojamiento + suplemento, NO sobre `$total`: la limpieza no entra en la
            // base del servicio. Es la regla de `PmsUnidad::servicioSobre()`, y calcularla
            // aquí sobre el total cobraba servicio sobre la limpieza.
            $servicio = round(($alojamiento + $suplemento) * $pct / 100, 2);
            $lineas[] = [
                'concepto' => sprintf('Cargo por servicio (%s%%)', rtrim(rtrim(sprintf('%.2f', $pct), '0'), '.')),
                'importe' => sprintf('%.2f', $servicio),
                // En una directa no se genera solo: si aparece, es porque se pidió.
                'origen' => 'lo añade el operador — en las reservas directas normalmente se exonera',
            ];
            $total += $servicio;
        }

        return ['lineas' => $lineas, 'total' => round($total, 2), 'moneda' => $moneda];
    }

    /**
     * Las cuatro cosas que dispara el INSERT, con nombre y apellidos.
     *
     * Ninguna la hace esta skill: las hacen listeners y servicios al hacer flush. Enumerarlas es
     * lo que separa «¿creo la reserva?» de «¿retiro esta casita de la venta en Booking y
     * programo dos mensajes al huésped?».
     *
     * @return list<string>
     */
    private function consecuencias(
        PmsUnidad $unidad,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        array $resumen
    ): array {
        $casita = $unidad->getNombre() ?? 'la casita';

        $lista = [
            sprintf(
                'Se crea la reserva y su estancia en %s, del %s al %s, en estado «%s».',
                $casita,
                $desde->format('Y-m-d'),
                $hasta->format('Y-m-d'),
                $resumen['estado']
            ),
            sprintf(
                'Se abre su cuenta con los cargos de arriba: %s en total.',
                $resumen['total_previsto']
            ),
            sprintf(
                '%s SALE DE LA VENTA esas noches en Beds24 y, por tanto, en todos los portales '
                . '(Booking, Airbnb…). No es instantáneo: viaja en la cola de push.',
                $casita
            ),
        ];

        if (($resumen['telefono'] ?? null) !== null) {
            $lista[] = sprintf(
                'Se programan mensajes automáticos al huésped: la guía de llegada para el %s y '
                . 'el aviso de salida para el %s. Le llegarán DE VERDAD, en %s.',
                $desde->format('Y-m-d'),
                $hasta->format('Y-m-d'),
                $resumen['idioma']
            );
        } else {
            $lista[] = 'Sin teléfono, los mensajes automáticos se programan pero NO podrán '
                . 'salir por WhatsApp. La reserva nace muda: si consigues el número, añádelo '
                . 'desde el panel.';
        }

        return $lista;
    }

    /** @param array<string,mixed> $resumen */
    private function crear(
        array $entrada,
        PmsUnidad $unidad,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        MaestroIdioma $idioma,
        string $estadoId,
        int $adultos,
        int $ninos,
        array $resumen,
        ActorInterface $actor
    ): SkillResult {
        $reserva = new PmsReserva();
        $reserva->setNombreCliente(trim((string) $entrada['nombre']));
        $reserva->setApellidoCliente(trim((string) ($entrada['apellido'] ?? '')));
        $reserva->setIdioma($idioma);
        $reserva->setEstablecimiento($unidad->getEstablecimiento());
        $reserva->setFechaLlegada($inicio);
        $reserva->setFechaSalida($fin);

        if (($tel = trim((string) ($entrada['telefono'] ?? ''))) !== '') {
            $reserva->setTelefono($tel);
        }

        if (($mail = trim((string) ($entrada['email'] ?? ''))) !== '') {
            $reserva->setEmailCliente($mail);
        }

        $this->em->persist($reserva);

        // La estancia la monta el creador COMPARTIDO con `crear_estancia`: horas del
        // establecimiento, estados maestros, disponibilidad y el canal DIRECTO —que es lo que
        // hace que los cargos automáticos se disparen—. Tenerlo escrito dos veces era
        // garantizar que las copias se separaran; ver el docblock de PmsEstanciaCreator.
        try {
            $evento = $this->estancias->crear(
                $reserva,
                $unidad,
                $inicio,
                $fin,
                $adultos,
                $ninos,
                $estadoId
            );
        } catch (InvalidArgumentException $e) {
            return SkillResult::error($e->getMessage());
        }

        $this->em->flush();

        // Los cargos ya los ha creado PmsCargosAutomaticosService en el flush. Aquí sólo se
        // aplican los desvíos que pidió el operador, encima de lo que el sistema puso: es más
        // seguro corregir lo generado que reimplementar la generación.
        $ajustes = $this->ajustarCargos($reserva, $entrada);

        return SkillResult::ok($resumen + [
            'creada' => true,
            'reserva_id' => (string) $reserva->getId(),
            'localizador' => $reserva->getLocalizador(),
            'evento_id' => (string) $evento->getId(),
            'ajustes_aplicados' => $ajustes !== [] ? $ajustes : null,
            'que_ha_pasado' => $this->consecuencias(
                $unidad,
                DateTimeImmutable::createFromInterface($inicio)->setTime(0, 0),
                DateTimeImmutable::createFromInterface($fin)->setTime(0, 0),
                $resumen
            ),
        ]);
    }

    /**
     * Aplica precio cerrado, perdón de limpieza y cargo de servicio sobre lo ya generado.
     *
     * @return list<string>
     */
    private function ajustarCargos(PmsReserva $reserva, array $entrada): array
    {
        $info = $this->em->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        if ($info === null) {
            return ['⚠️ no se encontró la cuenta: revisa los cargos a mano en el panel'];
        }

        $hechos = [];
        $precioFinal = trim((string) ($entrada['precio_final'] ?? ''));
        $sinLimpieza = strtolower(trim((string) ($entrada['cobrar_limpieza'] ?? 'si'))) === 'no';
        $pct = (float) str_replace(',', '.', (string) ($entrada['servicio_porcentaje'] ?? '0'));

        foreach ($info->getCargos() as $cargo) {
            /** @var PmsCargoFinanciero $cargo */
            if ($precioFinal !== '' && $cargo->getTipoCargo() === PmsTipoCargo::ALOJAMIENTO) {
                $importe = sprintf('%.2f', (float) str_replace(',', '.', $precioFinal));
                $cargo->setMonto($importe);
                $cargo->setTotalLinea($importe);
                $hechos[] = sprintf('Alojamiento fijado en %s (precio cerrado, no tarifario).', $importe);
            }

            if ($sinLimpieza && $cargo->getTipoCargo() === PmsTipoCargo::LIMPIEZA) {
                $info->removeCargo($cargo);
                $this->em->remove($cargo);
                $hechos[] = 'Suplemento de limpieza retirado.';
            }
        }

        if ($pct > 0) {
            $this->em->flush();

            $base = (float) $info->getTotalCargos();
            $servicio = round($base * $pct / 100, 2);

            $cargo = new PmsCargoFinanciero();
            $cargo->setTipoCargo(PmsTipoCargo::SERVICIO);
            $cargo->setDescripcion(sprintf('Cargo por servicio (%s%%)', rtrim(rtrim(sprintf('%.2f', $pct), '0'), '.')));
            $cargo->setMonto(sprintf('%.2f', $servicio));
            $cargo->setTotalLinea(sprintf('%.2f', $servicio));
            $cargo->setMoneda($info->getMoneda());

            $info->addCargo($cargo);
            $this->em->persist($cargo);

            $hechos[] = sprintf('Cargo por servicio del %s%% añadido: %.2f.', $pct, $servicio);
        }

        if ($hechos !== []) {
            $this->em->flush();
        }

        return $hechos;
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
