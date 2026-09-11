<?php

declare(strict_types=1);

namespace App\Pms\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Retira las reservas FANTASMA: espejos de Beds24 que el pull adoptó como si fueran reservas.
 *
 * ── De dónde salen ──────────────────────────────────────────────────────────
 * Un espejo es un booking que creamos nosotros para tapar el listing gemelo, y su sitio es un link
 * `es_principal = 0` colgando del evento de la estancia real. Cuando ese link se perdía y el
 * espejo volvía por el barrido, el pull no podía reconocerlo —hasta el 30/07/2026 los espejos no
 * llevaban `custom2 = 'MIRROR'`— y lo adoptaba como principal: evento nuevo con título «(M) …»,
 * reserva nueva y a veces hilo de chat. Desde el 10/09/2026 `BookingPullPersister` se niega a
 * estrenar nada para un espejo que ningún link reclama; esto limpia lo que quedó de antes.
 *
 * ── Por qué los localizadores se pasan A MANO ───────────────────────────────
 * «Todos sus eventos se titulan (M) …» no basta para saber que una reserva sobra. Al medirlo el
 * 10/09/2026 salieron 17, y **seis no tenían ninguna otra estancia en esa casita esas noches**:
 * teléfono real, y a dos de ellos les habían llegado la guía y el check-out por WhatsApp. Todo
 * apunta a que ahí el fantasma es **el único registro que queda de una estancia de verdad**.
 * Eso sólo lo puede decidir alguien que conozca a los huéspedes, así que el comando no elige:
 * ejecuta una lista que ya decidió una persona, y comprueba que cada entrada es segura.
 *
 * ── Qué comprueba antes de tocar una reserva ────────────────────────────────
 * Si falla cualquiera, la reserva se rechaza con su motivo, y con `--ejecutar` **no se toca
 * ninguna** hasta que se quite de la lista. Mejor una pasada que no hace nada que una a medias.
 *
 * - Todos sus eventos principales son «(M) …». Si no, no es un fantasma.
 * - Todas sus fechas han pasado. El barrido arranca en `hoy − 1` y nunca vuelve atrás, así que
 *   una pasada no reaparece; una futura sí, y además su booking puede estar tapando el listing.
 * - Sin cargos ni pagos. Con dinero de por medio no se borra: se decide.
 * - Sin mensajes programados ni envíos a Beds24 en curso.
 * - Cada hilo cuya CABECERA es el fantasma tiene que poder borrarse entero —ver abajo—. Si no,
 *   ese hilo es de alguien, y dejarlo con la cabecera apuntando a una reserva borrada sería peor.
 *
 * ── Qué se hace con los hilos ───────────────────────────────────────────────
 * Los hilos se fusionan por persona, así que el asunto fantasma puede colgar del hilo de un
 * huésped real. Dos casos:
 *
 * | el hilo | se hace |
 * |---|---|
 * | Su cabecera es otra reserva | se retira sólo el asunto; el hilo y sus mensajes se quedan |
 * | Su cabecera es el fantasma, sin más asuntos, sin entrantes, sin nada ENTREGADO a nadie y con identidades sólo de tipo `beds24` | se borra entero |
 *
 * El segundo incluye las cáscaras que deja la fusión: hilos vacíos con `fusionado_en` que
 * conservan la cabecera vieja. Nadie las lee para redirigir —`fusionado_en` sólo lo usan la
 * fusión y su barrido— y dejarlas las dejaría apuntando a una reserva que ya no existe.
 *
 * Los mensajes del asunto fantasma que quedan en un hilo compartido **se conservan**: son lo que
 * se le mandó o se le intentó mandar a esa persona. El sistema ya contempla mensajes cuyo asunto
 * dejó de colgar del hilo («asunto retirado», `MessageRuleEngine::cancelarPendientesDeAsuntosRetirados()`),
 * y ninguno de estos está pendiente —es una de las comprobaciones de arriba—.
 *
 * ── Por qué esto no contradice «no se borra: se marca» ──────────────────────
 * `CLAUDE.md` pide no borrar lo que forma parte de lo que le pasó a una persona. Lo que se borra
 * aquí **no le pasó a nadie**: son copias de una reserva que existe en otra parte, o de un bloqueo.
 * Lo que sí es historia —lo que se le mandó a alguien— se queda: por eso los mensajes de los hilos
 * compartidos no se tocan, y por eso un hilo con algo entregado bloquea la reserva entera.
 *
 * ── Por qué SQL y no ORM ────────────────────────────────────────────────────
 * Al revés que `app:pms:cabeceras:huerfanas`, que va por ORM porque ahí los listeners DEBEN
 * correr. Aquí son justo el problema:
 *
 * - `Beds24BookingsPushQueueListener` encolaría un DELETE a Beds24 **por cada link** (§12.12.2).
 *   Uno de los dos links de un fantasma apunta a un booking de verdad —el espejo cuyo link se
 *   perdió—, y Beds24 sólo borra reservas canceladas. No hay nada que ganar allí: son fechas
 *   pasadas y el barrido no vuelve.
 * - `PmsReservaDeleteListener` vetaría las confirmadas «que existen en Beds24» (§12.12.1), que es
 *   la regla correcta para una reserva y la incorrecta para algo que nunca debió serlo.
 *
 * El orden lo marcan las claves foráneas: enlaces y hilos, cabecera financiera, eventos —sus links
 * caen en cascada y las colas de push históricas quedan con `link_id` a NULL, que es lo que ya
 * hace el ORM al borrar (§12.11.b)— y al final la reserva. Una transacción por reserva.
 *
 * ── Antes de borrar, copia ──────────────────────────────────────────────────
 * Con `--ejecutar`, primero se vuelca a un JSONL cada fila que va a desaparecer —y el `link_id`
 * de las colas que se van a desenganchar—. **Si el respaldo no se puede escribir, no se borra
 * nada**: al revés que en `app:message:purgar-traza`, que sigue sin él, porque aquí lo que se
 * pierde no es una traza de depuración.
 *
 * ── Ensayo ──────────────────────────────────────────────────────────────────
 * `--ensayo` hace el borrado entero dentro de UNA transacción, comprueba que desapareció
 * exactamente lo previsto —y que lo que debía quedarse sigue ahí— y la deshace. Es la forma que
 * pide `CLAUDE.md` para lo que los tests unitarios no pueden cubrir: cinco pasos encadenados por
 * claves foráneas sólo se prueban de verdad contra las filas reales.
 *
 * Uso:
 *   php bin/console app:pms:retirar-fantasmas UDKAY9 N2E6SC            (sólo dice qué haría)
 *   php bin/console app:pms:retirar-fantasmas UDKAY9 N2E6SC --ensayo   (borra y deshace)
 *   php bin/console app:pms:retirar-fantasmas UDKAY9 N2E6SC --ejecutar
 *
 * Ver docs/PmsBeds24ReservasSync.md §6.3.d.
 */
#[AsCommand(
    name: 'app:pms:retirar-fantasmas',
    description: 'Retira reservas fantasma (espejos adoptados por el pull). Sin --ejecutar sólo informa.'
)]
final class PmsRetirarReservasFantasmaCommand extends Command
{
    /** Estados de un mensaje que prueban que le llegó a alguien. */
    private const array ENTREGADOS = ['sent', 'delivered', 'read'];

    public function __construct(
        private readonly Connection $conexion,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $raizDelProyecto,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizadores', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Localizadores de las reservas a retirar.')
            ->addOption('ejecutar', null, InputOption::VALUE_NONE, 'Borra de verdad. Sin esto sólo dice qué haría.')
            ->addOption('ensayo', null, InputOption::VALUE_NONE, 'Borra dentro de una transacción, comprueba el resultado y la deshace.')
            ->addOption('respaldo', null, InputOption::VALUE_REQUIRED, 'Archivo JSONL donde volcar lo que se borra (por defecto, en var/respaldos/).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ejecutar = (bool) $input->getOption('ejecutar');

        /** @var list<string> $localizadores */
        $localizadores = array_values(array_unique(array_map(
            static fn (string $l): string => strtoupper(trim($l)),
            (array) $input->getArgument('localizadores')
        )));

        $planes = array_map($this->planificar(...), $localizadores);

        $io->table(
            ['Reserva', 'Veredicto', 'Eventos', 'Links', 'Hilos que se borran', 'Hilos donde sólo se retira el asunto', 'Mensajes que se quedan'],
            array_map(static fn (array $p): array => [
                $p['localizador'],
                $p['motivo'] === null ? 'se puede' : 'NO',
                $p['eventos'],
                $p['links'],
                count($p['hilosABorrar']),
                count($p['hilosCompartidos']),
                $p['mensajesQueSeQuedan'],
            ], $planes)
        );

        $rechazadas = array_values(array_filter($planes, static fn (array $p): bool => $p['motivo'] !== null));

        foreach ($rechazadas as $p) {
            $io->warning(sprintf('%s: %s', $p['localizador'], $p['motivo']));
        }

        $ensayo = (bool) $input->getOption('ensayo');

        if ($ensayo && $ejecutar) {
            $io->error('--ensayo y --ejecutar son excluyentes.');

            return Command::FAILURE;
        }

        if ($ensayo) {
            if ($rechazadas !== []) {
                $io->error('Hay reservas rechazadas: el ensayo se hace sólo con una lista limpia.');

                return Command::FAILURE;
            }

            return $this->ensayar($io, $planes);
        }

        if (!$ejecutar) {
            $io->note('Modo informe: no se ha borrado nada. Para probar el borrado sin consecuencias, --ensayo; para borrar, --ejecutar.');

            return $rechazadas === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($rechazadas !== []) {
            $io->error('Hay reservas rechazadas: no se toca NINGUNA. Quítalas de la lista y vuelve a lanzarlo.');

            return Command::FAILURE;
        }

        $ruta = $this->rutaDeRespaldo($input->getOption('respaldo'));

        if (!$this->respaldar($io, $ruta, $planes)) {
            $io->error('Sin respaldo no se borra nada.');

            return Command::FAILURE;
        }

        foreach ($planes as $p) {
            $this->conexion->transactional(fn () => $this->retirar($p));
            $io->text(sprintf('  ✔ %s retirada', $p['localizador']));
        }

        $io->success(sprintf('%d reservas retiradas. Respaldo en %s.', count($planes), $ruta));

        return Command::SUCCESS;
    }

    /**
     * Qué se haría con una reserva, y si se puede. No escribe nada.
     *
     * @return array{
     *     localizador: string, motivo: ?string, reservaHex: ?string, uuid: ?string,
     *     eventos: int, links: int, hilosABorrar: list<string>, hilosCompartidos: list<string>,
     *     mensajesQueSeQuedan: int
     * }
     */
    private function planificar(string $localizador): array
    {
        $plan = [
            'localizador' => $localizador, 'motivo' => null, 'reservaHex' => null, 'uuid' => null,
            'eventos' => 0, 'links' => 0, 'hilosABorrar' => [], 'hilosCompartidos' => [],
            'mensajesQueSeQuedan' => 0,
        ];

        $reserva = $this->conexion->fetchAssociative(
            'SELECT HEX(id) AS hex, BIN_TO_UUID(id) AS uuid FROM pms_reserva WHERE localizador = ?',
            [$localizador]
        );

        if ($reserva === false) {
            return ['motivo' => 'no existe esa reserva.'] + $plan;
        }

        $hex = (string) $reserva['hex'];
        $uuid = (string) $reserva['uuid'];
        $plan['reservaHex'] = $hex;
        $plan['uuid'] = $uuid;

        $ev = $this->conexion->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    SUM(evento_origen_id IS NULL) AS principales,
                    SUM(evento_origen_id IS NULL AND COALESCE(titulo_cache, "") NOT LIKE "(M)%") AS no_espejo,
                    SUM(fin >= NOW()) AS futuros
               FROM pms_evento_calendario WHERE reserva_id = UNHEX(?)',
            [$hex]
        );
        $plan['eventos'] = (int) ($ev['total'] ?? 0);
        $plan['links'] = (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM pms_evento_beds24_link l
               JOIN pms_evento_calendario e ON e.id = l.evento_id WHERE e.reserva_id = UNHEX(?)',
            [$hex]
        );

        [$hilosABorrar, $hilosCompartidos, $motivoHilo] = $this->clasificarHilos($hex, $uuid);
        $plan['hilosABorrar'] = $hilosABorrar;
        $plan['hilosCompartidos'] = $hilosCompartidos;

        if ($hilosCompartidos !== []) {
            $plan['mensajesQueSeQuedan'] = (int) $this->conexion->fetchOne(
                'SELECT COUNT(*) FROM msg_message WHERE asunto_id = ? AND HEX(conversation_id) IN (?)',
                [$uuid, $hilosCompartidos],
                [ParameterType::STRING, ArrayParameterType::STRING]
            );
        }

        $motivo = match (true) {
            (int) ($ev['principales'] ?? 0) === 0 => 'no tiene eventos: no es un fantasma de espejo.',
            (int) ($ev['no_espejo'] ?? 0) > 0 => 'tiene eventos que no son «(M) …»: no es un fantasma.',
            (int) ($ev['futuros'] ?? 0) > 0 => 'tiene fechas por venir: el pull volvería a traerla y su booking puede estar tapando el listing gemelo.',
            $this->tieneDinero($hex) => 'tiene cargos o pagos: con dinero de por medio no se borra, se decide.',
            $this->tieneAsignaciones($hex) => 'tiene asignaciones de personal (pms_event_assignment).',
            $this->tieneProgramados($uuid) => 'tiene mensajes programados todavía por salir.',
            $this->tienePushEnCurso($hex) => 'hay un envío a Beds24 en curso sobre sus links.',
            default => $motivoHilo,
        };

        return ['motivo' => $motivo] + $plan;
    }

    /**
     * Separa los hilos que se borran enteros de los que sólo pierden el asunto.
     *
     * @return array{0: list<string>, 1: list<string>, 2: ?string} [a borrar, compartidos, motivo de rechazo]
     */
    private function clasificarHilos(string $hex, string $uuid): array
    {
        // Los enlazados al fantasma y los que llevan su cabecera: una cáscara de fusión tiene lo
        // segundo sin lo primero, y también hay que retirarla.
        $entregados = implode(', ', array_map($this->conexion->quote(...), self::ENTREGADOS));

        $hilos = $this->conexion->fetchAllAssociative(
            'SELECT HEX(c.id) AS hex, c.guest_name, c.context_id = ? AS con_su_cabecera,
                    (SELECT COUNT(*) FROM pms_conversacion_enlace o WHERE o.conversacion_id = c.id AND o.reserva_id <> UNHEX(?)) AS otros_asuntos,
                    (SELECT COUNT(*) FROM cotizacion_conversacion_enlace o WHERE o.conversacion_id = c.id) AS asuntos_cotizacion,
                    (SELECT COUNT(*) FROM msg_message m WHERE m.conversation_id = c.id AND m.direction = "incoming") AS entrantes,
                    (SELECT COUNT(*) FROM msg_message m WHERE m.conversation_id = c.id AND m.status IN (' . $entregados . ')) AS entregados,
                    (SELECT COUNT(*) FROM msg_identidad i WHERE i.conversacion_id = c.id AND i.tipo <> "beds24") AS identidades_de_persona
               FROM msg_conversation c
              WHERE c.context_id = ?
                 OR c.id IN (SELECT l.conversacion_id FROM pms_conversacion_enlace l WHERE l.reserva_id = UNHEX(?))',
            [$uuid, $hex, $uuid, $hex]
        );

        $aBorrar = [];
        $compartidos = [];

        foreach ($hilos as $h) {
            if (!(bool) $h['con_su_cabecera']) {
                $compartidos[] = (string) $h['hex'];
                continue;
            }

            $porQueNo = match (true) {
                (int) $h['otros_asuntos'] > 0 || (int) $h['asuntos_cotizacion'] > 0 => 'cuelgan de él otros asuntos',
                (int) $h['entrantes'] > 0 => 'tiene mensajes que escribió alguien',
                (int) $h['entregados'] > 0 => 'le llegaron mensajes a una persona',
                (int) $h['identidades_de_persona'] > 0 => 'guarda el teléfono o el correo de alguien',
                default => null,
            };

            if ($porQueNo !== null) {
                return [[], [], sprintf(
                    'el hilo «%s» lleva su cabecera y %s: puede ser el único registro de una estancia real. Esto lo decide una persona.',
                    (string) $h['guest_name'],
                    $porQueNo
                )];
            }

            $aBorrar[] = (string) $h['hex'];
        }

        return [$aBorrar, $compartidos, null];
    }

    private function tieneDinero(string $hex): bool
    {
        return (int) $this->conexion->fetchOne(
            'SELECT (SELECT COUNT(*) FROM pms_cargo_financiero c JOIN pms_informacion_financiera f ON f.id = c.informacion_id WHERE f.reserva_id = UNHEX(:r))
                  + (SELECT COUNT(*) FROM pms_pago_financiero p JOIN pms_informacion_financiera f ON f.id = p.informacion_id WHERE f.reserva_id = UNHEX(:r))
                  + (SELECT COUNT(*) FROM pms_cargo_financiero c JOIN pms_evento_calendario e ON e.id = c.evento_id WHERE e.reserva_id = UNHEX(:r))',
            ['r' => $hex]
        ) > 0;
    }

    private function tieneAsignaciones(string $hex): bool
    {
        return (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM pms_event_assignment a JOIN pms_evento_calendario e ON e.id = a.evento_id WHERE e.reserva_id = UNHEX(?)',
            [$hex]
        ) > 0;
    }

    private function tieneProgramados(string $uuid): bool
    {
        return (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM msg_message WHERE asunto_id = ? AND status IN ("pending", "queued")',
            [$uuid]
        ) > 0;
    }

    private function tienePushEnCurso(string $hex): bool
    {
        return (int) $this->conexion->fetchOne(
            'SELECT COUNT(*) FROM pms_bookings_push_queue q
               JOIN pms_evento_beds24_link l ON l.id = q.link_id
               JOIN pms_evento_calendario e ON e.id = l.evento_id
              WHERE e.reserva_id = UNHEX(?)
                AND (q.status NOT IN ("success", "failed", "cancelled") OR q.locked_at IS NOT NULL)',
            [$hex]
        ) > 0;
    }

    /**
     * El borrado, en el orden que imponen las claves foráneas. Corre dentro de una transacción.
     *
     * @param array{reservaHex: ?string, uuid: ?string, hilosABorrar: list<string>} $p
     */
    private function retirar(array $p): void
    {
        $r = (string) $p['reservaHex'];

        // 1. El asunto sale de todos sus hilos, compartidos o no.
        $this->conexion->executeStatement('DELETE FROM pms_conversacion_enlace WHERE reserva_id = UNHEX(?)', [$r]);

        // 2. Los hilos que eran sólo suyos. En cascada: identidades, mensajes y sus colas y adjuntos.
        foreach ($p['hilosABorrar'] as $hilo) {
            $this->conexion->executeStatement('DELETE FROM msg_conversation WHERE id = UNHEX(?)', [$hilo]);
        }

        // 3. La cabecera financiera, vacía por comprobación (sus totales por moneda caen en cascada).
        $this->conexion->executeStatement('DELETE FROM pms_informacion_financiera WHERE reserva_id = UNHEX(?)', [$r]);

        // 4. Los eventos: primero las extensiones, que apuntan a su evento de origen. Sus links caen
        //    en cascada y las colas de push históricas se quedan con `link_id` a NULL.
        $this->conexion->executeStatement('DELETE FROM pms_evento_calendario WHERE reserva_id = UNHEX(?) AND evento_origen_id IS NOT NULL', [$r]);
        $this->conexion->executeStatement('DELETE FROM pms_evento_calendario WHERE reserva_id = UNHEX(?)', [$r]);

        // 5. La reserva (sus huéspedes caen en cascada).
        $this->conexion->executeStatement('DELETE FROM pms_reserva WHERE id = UNHEX(?)', [$r]);
    }

    /**
     * Borra dentro de una transacción, comprueba que salió lo previsto y la deshace.
     *
     * @param list<array{localizador: string, reservaHex: ?string, uuid: ?string, hilosABorrar: list<string>, hilosCompartidos: list<string>, mensajesQueSeQuedan: int}> $planes
     */
    private function ensayar(SymfonyStyle $io, array $planes): int
    {
        // Lo que tiene que haber desaparecido o seguir en pie, contado ANTES de borrar: después
        // no quedaría a qué preguntarle por los links de un evento que ya no existe.
        $antes = [];

        foreach ($planes as $p) {
            $r = (string) $p['reservaHex'];
            $antes[$p['localizador']] = [
                'links' => $this->conexion->fetchFirstColumn(
                    'SELECT HEX(l.id) FROM pms_evento_beds24_link l JOIN pms_evento_calendario e ON e.id = l.evento_id WHERE e.reserva_id = UNHEX(?)',
                    [$r]
                ),
                'colas' => $this->conexion->fetchFirstColumn(
                    'SELECT HEX(q.id) FROM pms_bookings_push_queue q JOIN pms_evento_beds24_link l ON l.id = q.link_id JOIN pms_evento_calendario e ON e.id = l.evento_id WHERE e.reserva_id = UNHEX(?)',
                    [$r]
                ),
            ];
        }

        $filas = [];
        $todoBien = true;

        $this->conexion->beginTransaction();

        try {
            foreach ($planes as $p) {
                $this->retirar($p);
            }

            foreach ($planes as $p) {
                $r = (string) $p['reservaHex'];
                $a = $antes[$p['localizador']];
                $contar = fn (string $sql, array $params, array $tipos = []): int => (int) $this->conexion->fetchOne($sql, $params, $tipos);
                $lista = [ArrayParameterType::STRING];

                $comprobaciones = [
                    'reserva borrada'           => [$contar('SELECT COUNT(*) FROM pms_reserva WHERE id = UNHEX(?)', [$r]), 0],
                    'eventos borrados'          => [$contar('SELECT COUNT(*) FROM pms_evento_calendario WHERE reserva_id = UNHEX(?)', [$r]), 0],
                    'links borrados'            => [$a['links'] === [] ? 0 : $contar('SELECT COUNT(*) FROM pms_evento_beds24_link WHERE HEX(id) IN (?)', [$a['links']], $lista), 0],
                    'colas desenganchadas'      => [$a['colas'] === [] ? 0 : $contar('SELECT COUNT(*) FROM pms_bookings_push_queue WHERE HEX(id) IN (?) AND link_id IS NULL', [$a['colas']], $lista), count($a['colas'])],
                    'enlaces retirados'         => [$contar('SELECT COUNT(*) FROM pms_conversacion_enlace WHERE reserva_id = UNHEX(?)', [$r]), 0],
                    'hilos propios borrados'    => [$p['hilosABorrar'] === [] ? 0 : $contar('SELECT COUNT(*) FROM msg_conversation WHERE HEX(id) IN (?)', [$p['hilosABorrar']], $lista), 0],
                    'hilos compartidos en pie'  => [$p['hilosCompartidos'] === [] ? 0 : $contar('SELECT COUNT(*) FROM msg_conversation WHERE HEX(id) IN (?)', [$p['hilosCompartidos']], $lista), count($p['hilosCompartidos'])],
                    'sus mensajes siguen'       => [$contar('SELECT COUNT(*) FROM msg_message WHERE asunto_id = ?', [(string) $p['uuid']]), $p['mensajesQueSeQuedan']],
                ];

                foreach ($comprobaciones as $que => [$hay, $esperado]) {
                    $bien = $hay === $esperado;
                    $todoBien = $todoBien && $bien;
                    $filas[] = [$p['localizador'], $que, $esperado, $hay, $bien ? '✔' : '✘'];
                }
            }
        } finally {
            // Pase lo que pase —incluida una excepción a mitad—, el ensayo no deja rastro.
            $this->conexion->rollBack();
        }

        $io->table(['Reserva', 'Comprobación', 'Esperado', 'Hay', ''], $filas);

        if (!$todoBien) {
            $io->error('El ensayo NO salió como se esperaba. No se ha borrado nada (transacción deshecha).');

            return Command::FAILURE;
        }

        $io->success('Ensayo correcto: el borrado hace exactamente lo previsto. Transacción deshecha: no se ha tocado nada.');

        return Command::SUCCESS;
    }

    private function rutaDeRespaldo(mixed $opcion): string
    {
        if (is_string($opcion) && $opcion !== '') {
            return $opcion;
        }

        $dir = $this->raizDelProyecto . '/var/respaldos';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return sprintf('%s/reservas-fantasma-%s.jsonl', $dir, date('Ymd-His'));
    }

    /**
     * Vuelca cada fila que va a desaparecer, y el `link_id` de las colas que se desenganchan.
     *
     * @param list<array{localizador: string, reservaHex: ?string, hilosABorrar: list<string>}> $planes
     */
    private function respaldar(SymfonyStyle $io, string $ruta, array $planes): bool
    {
        $manejador = @fopen($ruta, 'wb');

        if ($manejador === false) {
            $io->warning(sprintf('No se pudo abrir %s para escribir.', $ruta));

            return false;
        }

        $n = 0;

        foreach ($planes as $p) {
            $r = (string) $p['reservaHex'];
            $consultas = [
                'pms_reserva'                  => ['SELECT * FROM pms_reserva WHERE id = UNHEX(?)', [$r]],
                'pms_reserva_huesped'          => ['SELECT * FROM pms_reserva_huesped WHERE reserva_id = UNHEX(?)', [$r]],
                'pms_informacion_financiera'   => ['SELECT * FROM pms_informacion_financiera WHERE reserva_id = UNHEX(?)', [$r]],
                'pms_evento_calendario'        => ['SELECT * FROM pms_evento_calendario WHERE reserva_id = UNHEX(?)', [$r]],
                'pms_evento_beds24_link'       => ['SELECT l.* FROM pms_evento_beds24_link l JOIN pms_evento_calendario e ON e.id = l.evento_id WHERE e.reserva_id = UNHEX(?)', [$r]],
                'pms_bookings_push_queue'      => ['SELECT q.id, q.link_id FROM pms_bookings_push_queue q JOIN pms_evento_beds24_link l ON l.id = q.link_id JOIN pms_evento_calendario e ON e.id = l.evento_id WHERE e.reserva_id = UNHEX(?)', [$r]],
                'pms_conversacion_enlace'      => ['SELECT * FROM pms_conversacion_enlace WHERE reserva_id = UNHEX(?)', [$r]],
            ];

            foreach ($p['hilosABorrar'] as $hilo) {
                $consultas['msg_conversation#' . $hilo]             = ['SELECT * FROM msg_conversation WHERE id = UNHEX(?)', [$hilo]];
                $consultas['msg_identidad#' . $hilo]                = ['SELECT * FROM msg_identidad WHERE conversacion_id = UNHEX(?)', [$hilo]];
                $consultas['msg_message#' . $hilo]                  = ['SELECT * FROM msg_message WHERE conversation_id = UNHEX(?)', [$hilo]];
                $consultas['msg_whatsapp_meta_send_queue#' . $hilo] = ['SELECT q.* FROM msg_whatsapp_meta_send_queue q JOIN msg_message m ON m.id = q.message_id WHERE m.conversation_id = UNHEX(?)', [$hilo]];
                $consultas['msg_beds24_send_queue#' . $hilo]        = ['SELECT q.* FROM msg_beds24_send_queue q JOIN msg_message m ON m.id = q.message_id WHERE m.conversation_id = UNHEX(?)', [$hilo]];
                $consultas['msg_email_send_queue#' . $hilo]         = ['SELECT q.* FROM msg_email_send_queue q JOIN msg_message m ON m.id = q.message_id WHERE m.conversation_id = UNHEX(?)', [$hilo]];
                $consultas['msg_attachment#' . $hilo]               = ['SELECT a.* FROM msg_attachment a JOIN msg_message m ON m.id = a.message_id WHERE m.conversation_id = UNHEX(?)', [$hilo]];
            }

            foreach ($consultas as $tabla => [$sql, $params]) {
                foreach ($this->conexion->fetchAllAssociative($sql, $params) as $fila) {
                    $linea = json_encode(
                        ['reserva' => $p['localizador'], 'tabla' => explode('#', $tabla)[0], 'fila' => $this->legible($fila)],
                        JSON_UNESCAPED_UNICODE
                    );

                    if ($linea === false || fwrite($manejador, $linea . "\n") === false) {
                        fclose($manejador);
                        $io->warning(sprintf('Falló la escritura del respaldo en %s.', $ruta));

                        return false;
                    }

                    $n++;
                }
            }
        }

        fclose($manejador);
        clearstatcache(true, $ruta);
        $tamanio = (int) @filesize($ruta);

        // Un respaldo que no se comprueba no es un respaldo: es un nombre de fichero. El del
        // 21/08/2026 (§12.14) existía, se llamaba bien y pesaba 0 bytes; se supo después de
        // borrar. Como mínimo tiene que haber una fila por reserva —la propia reserva—.
        if ($n < count($planes) || $tamanio === 0) {
            $io->warning(sprintf('El respaldo en %s tiene %d filas y %d bytes: no es creíble.', $ruta, $n, $tamanio));

            return false;
        }

        $io->text(sprintf('Respaldo: %d filas, %s KB, en %s.', $n, number_format($tamanio / 1024, 1), $ruta));

        return true;
    }

    /**
     * Los UUID `BINARY(16)` van en hex: tal cual, `json_encode()` los rechaza —y el respaldo del
     * 21/08/2026 salió vacío justo por eso (§12.14)—.
     *
     * ⚠️ No basta con mirar si son UTF-8 válido: 16 bytes al azar pueden serlo por casualidad, y
     * entonces se colarían como texto ilegible. Se reconocen por la forma del esquema —columna
     * `id` o `*_id` de 16 bytes— y, como red, cualquier otro valor que no sea UTF-8.
     *
     * @param array<string, mixed> $fila
     * @return array<string, mixed>
     */
    private function legible(array $fila): array
    {
        foreach ($fila as $columna => $valor) {
            if (!is_string($valor)) {
                continue;
            }

            $esUuidBinario = ($columna === 'id' || str_ends_with($columna, '_id')) && strlen($valor) === 16;

            if ($esUuidBinario || !mb_check_encoding($valor, 'UTF-8')) {
                $fila[$columna] = ['hex' => bin2hex($valor)];
            }
        }

        return $fila;
    }
}
