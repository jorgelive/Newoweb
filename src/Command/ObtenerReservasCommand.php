<?php
namespace App\Command;

use App\Oweb\Entity\ReservaChannel;
use App\Oweb\Entity\ReservaEstado;
use App\Oweb\Entity\ReservaReserva;
use Doctrine\ORM\EntityManagerInterface;
use ICal\ICal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Comando para leer iCal (Airbnb / Booking / VRBO) y sincronizar con ReservaReserva.
 *
 * Características principales:
 * - Logs verbosos para depuración.
 * - Soporte de Booking con overbooking, usando UIDs extendidos: UID#N:x
 *   (mismo UID base puede tener varias reservas, cada una con #N:x distinto).
 * - Auto-migración de registros antiguos Booking (UID base → UID#N:1).
 * - Reservas Booking nuevas se crean en estado INICIAL.
 * - Reservas reactivadas pasan a INICIAL.
 * - Se ignora "Airbnb (Not available)".
 * - No se envía correo por “duplicados Booking”.
 */
#[AsCommand(
    name: 'app:obtener-reservas',
    description: 'Obtiene las reservas desde iCal (Airbnb/Booking/VRBO) con logs de debug y soporte de overbooking.'
)]
class ObtenerReservasCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private ParameterBagInterface $params;

    public function __construct(
        EntityManagerInterface $entityManager,
        ParameterBagInterface $params
    ) {
        $this->entityManager = $entityManager;
        $this->params = $params;
        parent::__construct();
    }

    /**
     * Configuración del comando:
     *
     *  - --dry-run
     *      Ejecuta todo el flujo, pero NO hace flush en BD.
     *      Útil para ver logs sin tocar datos.
     *
     *  - --nexo-id=ID
     *      Solo procesa el nexo indicado (por ejemplo, un solo Booking),
     *      ideal para pruebas y depuración.
     */
    protected function configure(): void
    {
        $this
            ->setHelp('Lee los iCal de los nexos, crea/actualiza Reservas. Maneja overbooking de Booking creando UIDs extendidos #N:x.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecuta sin persistir cambios en BD.')
            ->addOption('nexo-id', null, InputOption::VALUE_REQUIRED, 'Procesa solo el nexo con este ID (para depurar).');
    }

    /** ===================== Helpers Overbooking ===================== */

    /**
     * Extrae el índice N del patrón "UID#N:x".
     *
     * Ejemplos:
     *   "abc#N:3"  => 3
     *   "abc"      => null
     */
    private function extractIndexFromExtendedUid(string $uid): ?int
    {
        if (preg_match('/#N:(\d+)$/', $uid, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Inicializa el "pool" de índices #N existentes para Booking.
     *
     * Agrupación:
     *   - Por UID base
     *   - Por unidad
     *   - Por canal
     *   (NO filtra por fechas)
     *
     * ¿Por qué sin fechas?
     *   Porque Booking puede enviar múltiples VEVENT con el mismo UID base
     *   pero con rangos de fechas distintos (overbooking). Queremos que cada
     *   una de esas reservas tenga un índice único (#N:1, #N:2, #N:3, ...),
     *   y que N no se repita, independientemente de las fechas.
     *
     * Retorna:
     *   [
     *     colaExistentes (array de UID extendidos existentes "UID#N:x" ordenados),
     *     siguienteIndex (int -> siguiente N libre para ese UID base+unidad+canal)
     *   ]
     */
    private function initBookingIndexPool(
        EntityManagerInterface $em,
                               $unidad,
                               $canal,
        string $baseUid
    ): array {
        $repo = $em->getRepository(ReservaReserva::class);

        // Trae TODOS los UID extendidos que empiecen con "baseUid#N:"
        // para esa unidad + canal, Sin considerar fechas.
        $existentes = $repo->createQueryBuilder('r')
            ->where('r.unit = :unit')
            ->andWhere('r.channel = :channel')
            ->andWhere('r.uid LIKE :pattern')
            ->setParameter('unit', $unidad)
            ->setParameter('channel', $canal)
            ->setParameter('pattern', $baseUid . '#N:%')
            ->getQuery()
            ->getResult();

        // Extrae los índices N (del "#N:x")
        $indices = [];
        foreach ($existentes as $r) {
            $idx = $this->extractIndexFromExtendedUid((string)$r->getUid());
            if ($idx !== null) {
                $indices[] = $idx;
            }
        }
        sort($indices);

        // Si no hay ninguno, el siguiente índice es 1.
        // Si hay existentes, siguiente = max(indices) + 1.
        $siguiente = empty($indices) ? 1 : (max($indices) + 1);

        // Cola de UID extendidos ordenados ("UID#N:1", "UID#N:2", ...)
        $colaExistentes = [];
        foreach ($indices as $idx) {
            $colaExistentes[] = $baseUid . '#N:' . $idx;
        }

        return [$colaExistentes, $siguiente];
    }

    /** ===================== Execute ===================== */

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Zona horaria para todo el comando
        $tz = new \DateTimeZone('America/Lima');

        try {
            // Fecha/hora actual (mutable) para setear en entidades si se requiere
            $ahora = new \DateTime('now', $tz);
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Excepción capturada al crear fecha actual: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        // Flags del comando
        $dryRun      = (bool)$input->getOption('dry-run');
        $nexoFilterId = $input->getOption('nexo-id');

        $output->writeln([
            sprintf('<info>%s</info> Iniciando proceso%s...', $ahora->format('Y-m-d H:i:s'), $dryRun ? ' (dry-run)' : ''),
            '============',
        ]);

        // 1) Obtener nexos desde la BD
        $nexosRepo = $this->entityManager->getRepository("App\Oweb\Entity\ReservaUnitnexo");

        if ($nexoFilterId) {
            // Si se pasa --nexo-id, solo ese
            $nexos = $nexosRepo->findBy(['id' => (int)$nexoFilterId]);
            if (!$nexos) {
                $output->writeln(sprintf('<comment>No se encontró el nexo con id=%s</comment>', $nexoFilterId));
                return Command::SUCCESS;
            }
            $output->writeln(sprintf('<comment>Filtrando por nexo id=%s</comment>', $nexoFilterId));
        } else {
            // Caso normal: todos los nexos
            $nexos = $nexosRepo->findAll();
        }

        // Contadores globales para resumen final
        $totalEventosLeidos   = 0;
        $totalInsertados      = 0;
        $totalActualizados    = 0;
        $totalCancelados      = 0;
        $totalReactivados     = 0;

        /**
         * Estructuras en memoria para Booking durante ESTA ejecución:
         *
         * bookingPools:
         *   - clave: groupKey = "uidBase|unitId"
         *   - valor: [colaExistentes (uids extendidos), siguienteIndex]
         *
         * bookingClaimed:
         *   - clave: groupKey
         *   - valor: array associative [uidExtendido => true] para marcar
         *            qué UID extendido ya fue asociado a un evento en este run.
         */
        $bookingPools   = [];
        $bookingClaimed = [];

        // 2) Recorrer cada nexo configurado
        foreach ($nexos as $nexo) {
            // Si el nexo está deshabilitado, se salta
            if ($nexo->isDeshabilitado()) {
                $output->writeln(sprintf('→ Nexo %d deshabilitado, saltando.', $nexo->getId()));
                continue;
            }

            // Inicializar lector de iCal
            $ical = new ICal(false, [
                'defaultSpan'      => 2,
                'defaultTimeZone'  => 'America/Lima',
                'defaultWeekStart' => 'MO',
            ]);

            // Tomamos reservas desde "hoy 00:00" en adelante
            $from = (new \DateTimeImmutable('today', $tz))->setTime(0, 0);

            // Reservas actuales en BD para este nexo+unidad+canal desde "from"
            $qb = $this->entityManager->createQueryBuilder()
                ->select('rr')
                ->from(ReservaReserva::class, 'rr')
                ->where('rr.unitnexo = :nexo')
                ->andWhere('rr.channel = :channel')
                ->andWhere('rr.unit = :unit')
                ->andWhere('rr.fechahorainicio >= :from')
                ->setParameter('nexo', $nexo)
                ->setParameter('channel', $nexo->getChannel())
                ->setParameter('unit', $nexo->getUnit())
                ->setParameter('from', $from)
                ->orderBy('rr.fechahorainicio', 'ASC');

            /** @var ReservaReserva[] $currentReservas */
            $currentReservas = $qb->getQuery()->getResult();

            // Mapa auxiliar para Booking: UID extendido -> ReservaReserva
            // Nos sirve para intentar emparejar cada VEVENT con la reserva más "parecida"
            // (misma fecha de inicio y, en lo posible, misma duración), evitando que
            // las modificaciones/cancelaciones parezcan aleatorias cuando hay overbooking.
            $bookingReservasPorUid = [];
            if ($nexo->getChannel()->getId() === ReservaChannel::DB_VALOR_BOOKING) {
                foreach ($currentReservas as $r) {
                    if ($r->getUid()) {
                        $bookingReservasPorUid[(string)$r->getUid()] = $r;
                    }
                }
            }

            $output->writeln(sprintf(
                '→ Nexo %d | Canal:%s | Unidad:%s | Enlace:%s | Reservas actuales:%d',
                $nexo->getId(),
                $nexo->getChannel()->getNombre(),
                method_exists($nexo->getUnit(), 'getNombre') ? $nexo->getUnit()->getNombre() : $nexo->getUnit()->getId(),
                $nexo->getEnlace(),
                count($currentReservas)
            ));

            // Cargar iCal remoto desde la URL del nexo
            try {
                $ical->initUrl($nexo->getEnlace());
            } catch (\Exception $e) {
                // Si falla el iCal de este nexo, se loguea y se pasa al siguiente
                $output->writeln(sprintf('<error>Excepción al leer iCal de nexo %d: %s</error>', $nexo->getId(), $e->getMessage()));
                continue;
            }

            // Canal numérico (ejemplo: 2:Airbnb, 3:Booking, 4:VRBO, según tu enum)
            $canal           = $nexo->getChannel()->getId();
            $unidad          = $nexo->getUnit();
            $establecimiento = $unidad->getEstablecimiento();

            // Array de keys para saber qué reservas siguen "presentes" en el feed
            // Formato de cada key: uidExtendido|YmdIni|YmdFin
            $uidsArray = [];

            // 3) Procesar cada VEVENT del iCal
            foreach ($ical->events() as $event) {
                ++$totalEventosLeidos;

                // UID base del evento: si falta, generamos uno "fake" único
                $uid = $event->uid ?? null;
                if (!$uid) {
                    $uid = hash('sha1', implode('|', [
                        'ical-fallback',
                        (string)$nexo->getId(),
                        (string)$unidad->getId(),
                        (string)($event->dtstart ?? ''),
                        (string)($event->dtend ?? ''),
                        (string)($event->summary ?? ''),
                    ]));
                    $output->writeln(sprintf('<comment>[WARN] Evento sin UID, generando fallback: %s</comment>', $uid));
                }

                // Fechas crudas del iCal (formato Ymd)
                $dtstartRaw = $event->dtstart ?? '';
                $dtendRaw   = $event->dtend ?? '';
                // Summary / título del evento (Booking: puede traer "CLOSED - Not available", etc.)
                $summaryRaw = $event->summary ?? '';

                // Formato de fecha+hora para DateTime::createFromFormat
                $fmt      = 'Ymd H:i';
                // Horario de checkin/checkout definido en el Establecimiento
                $checkin  = $establecimiento->getCheckin()  ?? '14:00';
                $checkout = $establecimiento->getCheckout() ?? '10:00';

                // Construimos DateTime de inicio/fin con la hora de checkin/checkout
                $start = \DateTime::createFromFormat($fmt, $dtstartRaw . ' ' . $checkin, $tz);
                $end   = \DateTime::createFromFormat($fmt, $dtendRaw . ' ' . $checkout, $tz);

                if (!$start || !$end) {
                    $output->writeln(sprintf(
                        '<error>[SKIP] No pude parsear fechas: "%s %s" / "%s %s" (UID:%s)</error>',
                        $dtstartRaw,
                        $checkin,
                        $dtendRaw,
                        $checkout,
                        $uid
                    ));
                    continue;
                }

                // === Soporte Booking: convertir UID base a UID extendido UID#N:x ===
                $uidParaBD = $uid; // Valor por defecto para canales que no son Booking
                $uidTag    = '';   // Tag informativo N:x para logs

                if ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    /**
                     * groupKey:
                     *   agrupa por UID base + unidad (NO por fechas).
                     *
                     *   Esto permite que:
                     *     - Mismo UID base + unidad pueda tener varias reservas
                     *       (overbooking) con diferentes fechas.
                     *     - Cada una tendrá UID#N:x distinto.
                     */
                    $groupKey = $uid . '|' . (string)$unidad->getId();

                    // Si aún no se ha inicializado el pool para este UID base + unidad
                    if (!isset($bookingPools[$groupKey])) {
                        // Inicializar pool leyendo de BD todos los UID#N:x existentes
                        [$colaExistentes, $siguiente] = $this->initBookingIndexPool(
                            $this->entityManager,
                            $unidad,
                            $nexo->getChannel(),
                            $uid
                        );
                        $bookingPools[$groupKey]   = [$colaExistentes, $siguiente];
                        $bookingClaimed[$groupKey] = []; // todavía ningún UID#N:x “reclamado” en este run
                    }

                    // Obtenemos el estado actual del pool para este groupKey
                    [$colaExistentes, $siguiente] = $bookingPools[$groupKey];

                    /**
                     * 1) Intentar reusar un UID#N:x ya existente en BD que:
                     *    - Pertenezca a este UID base + unidad + canal
                     *    - No haya sido "reclamado" en esta ejecución
                     *    - Sea el que mejor coincide con la reserva según:
                     *          a) misma fecha de inicio (Booking ata el UID a la fecha de inicio)
                     *          b) misma duración (número de noches), si es posible
                     *
                     *    De este modo, cuando Booking modifica o cancela solo una
                     *    de las reservas con el mismo UID base, la asociación entre
                     *    VEVENT y reserva no es "al azar" sino lo más coherente posible.
                     */
                    $reusado = null;

                    if (!empty($colaExistentes)) {
                        $bestScore = null;
                        $bestUid   = null;

                        $targetNights = $start->diff($end)->days;
                        $targetStart  = $dtstartRaw; // Ymd

                        foreach ($colaExistentes as $candidatoUid) {
                            if (isset($bookingClaimed[$groupKey][$candidatoUid])) {
                                // Ya usamos este UID extendido para otro evento en este run
                                continue;
                            }

                            // Score alto por defecto; mientras menor, mejor match
                            $score = 1000;

                            if (isset($bookingReservasPorUid[$candidatoUid])) {
                                $res = $bookingReservasPorUid[$candidatoUid];
                                $ini = $res->getFechahorainicio();
                                $fin = $res->getFechahorafin();

                                $iniYmd = $ini->format('Ymd');
                                $nights = $ini->diff($fin)->days;

                                // Booking: UID amarrado a la fecha de inicio.
                                // Prioridad:
                                //  - score 0: misma fecha inicio + mismas noches
                                //  - score 1..N: misma fecha inicio, noches distintas
                                //  - score 10+: fecha inicio distinta (debería ser raro)
                                if ($iniYmd === $targetStart && $nights === $targetNights) {
                                    $score = 0;
                                } elseif ($iniYmd === $targetStart) {
                                    $score = 1 + abs($nights - $targetNights);
                                } else {
                                    $score = 10 + abs($nights - $targetNights);
                                }
                            }

                            if ($bestScore === null || $score < $bestScore) {
                                $bestScore = $score;
                                $bestUid   = $candidatoUid;
                            }
                        }

                        if ($bestUid !== null) {
                            $reusado = $bestUid;
                            // Sacamos el UID elegido de la cola
                            $colaExistentes = array_values(array_diff($colaExistentes, [$bestUid]));
                        }
                    }

                    if ($reusado) {
                        // Reutilizamos este UID extendido
                        $uidParaBD = $reusado;
                        $uidTag    = 'N:' . $this->extractIndexFromExtendedUid($reusado);
                    } else {
                        /**
                         * 2) Si no hay ningún UID extendido disponible para reutilizar
                         *    en este grupo, generamos uno nuevo usando el siguiente índice:
                         *    UID#N:siguiente
                         *
                         *   Ejemplo:
                         *     base UID = abc
                         *     siguiente = 3
                         *     => UID para BD = "abc#N:3"
                         */
                        $uidParaBD = $uid . '#N:' . $siguiente;
                        $uidTag    = 'N:' . $siguiente;
                        $siguiente++;
                    }

                    // Guardamos el pool actualizado
                    $bookingPools[$groupKey] = [$colaExistentes, $siguiente];
                    // Marcamos este UID extendido como usado en este run
                    $bookingClaimed[$groupKey][$uidParaBD] = true;
                }

                // Clave única por evento para saber presencia en el feed:
                // UID EXTENDIDO + fechas Ymd de inicio y fin
                $eventKey = $uidParaBD . '|' . $dtstartRaw . '|' . $dtendRaw;

                // Si ya existe un evento idéntico (mismo UID extendido + mismas fechas),
                // lo logueamos como duplicado en el feed; no hacemos early return
                if (in_array($eventKey, $uidsArray, true) && $canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $output->writeln(sprintf('<comment>[DUP-FEED] Booking evento idéntico: %s</comment>', $eventKey));
                } else {
                    $uidsArray[] = $eventKey;
                }

                // Busca reservas existentes en BD por:
                //   - UID (ya extendido si Booking)
                //   - unidad
                //   - canal
                $existentes = $this->entityManager->getRepository(ReservaReserva::class)->findBy([
                    'uid'     => $uidParaBD,
                    'unit'    => $unidad,
                    'channel' => $nexo->getChannel(),
                ]);

                // === COMPATIBILIDAD hacia atrás (solo Booking):
                //
                // Antes se guardaban reservas Booking con uid base (sin #N:x).
                // Aquí, si no encontramos nada con UID extendido, intentamos buscar:
                //   - uid base
                //   - mismas fechas
                // Si existe, lo migramos a UID#N:1.
                $existentesPorFechas = [];
                if ($canal == ReservaChannel::DB_VALOR_BOOKING && empty($existentes)) {
                    $existenteBase = $this->entityManager->getRepository(ReservaReserva::class)->findOneBy([
                        'uid'             => $uid, // uid base
                        'unit'            => $unidad,
                        'channel'         => $nexo->getChannel(),
                        'fechahorainicio' => $start,
                        'fechahorafin'    => $end,
                    ]);
                    if ($existenteBase) {
                        $nuevoUid = $uid . '#N:1';
                        $output->writeln(sprintf(
                            '<comment>[MIGRATE]</comment> UID base "%s" → "%s" (id:%d)',
                            $existenteBase->getUid(),
                            $nuevoUid,
                            $existenteBase->getId()
                        ));
                        if (!$dryRun) {
                            $existenteBase->setUid($nuevoUid);
                        }
                        // A partir de ahora, tratamos esa reserva como si tuviera UID extendido
                        $uidParaBD  = $nuevoUid;
                        $existentes = [$existenteBase];
                    }
                }

                // Fallback diagnóstico: buscar por fechas (mismas fechas, mismo canal+unidad)
                if (empty($existentes)) {
                    $existentesPorFechas = $this->entityManager->getRepository(ReservaReserva::class)
                        ->createQueryBuilder('r')
                        ->where('r.unit = :unit')
                        ->andWhere('r.channel = :channel')
                        ->andWhere('r.fechahorainicio = :ini')
                        ->andWhere('r.fechahorafin = :fin')
                        ->setParameter('unit', $unidad)
                        ->setParameter('channel', $nexo->getChannel())
                        ->setParameter('ini', $start)
                        ->setParameter('fin', $end)
                        ->getQuery()
                        ->getResult();

                    if ($existentesPorFechas) {
                        $output->writeln(sprintf(
                            '<comment>[INFO] Encontrado por fechas (no por UID) UID:%s %s→%s</comment>',
                            $uidParaBD,
                            $start->format('Y-m-d H:i'),
                            $end->format('Y-m-d H:i')
                        ));
                    }
                }

                // Variables para el procesamiento de la reserva (crear/actualizar)
                $insertar = false;
                $estado   = null;
                $nombre   = '';
                $enlace   = '';

                /**
                 * Lógica según canal/summary:
                 *
                 * - Airbnb (Not available) -> se ignora.
                 * - Airbnb -> se crea en estado PAGO_TOTAL (Completar Airbnb).
                 * - Booking -> se crea en INICIAL (Completar Booking).
                 * - VRBO -> se crea en PAGO_TOTAL (Completar VRBO).
                 * - Otros -> INICIAL, nombre = summary raw.
                 */
                if ($summaryRaw === 'Airbnb (Not available)') {
                    // Bloqueos automáticos "Not available" de Airbnb no se manejan como reservas
                    $insertar = false;
                    $output->writeln(sprintf('[SKIP] Airbnb Not available (UID:%s)', $uid));
                } elseif ($canal == ReservaChannel::DB_VALOR_AIRBNB) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(
                        ReservaEstado::class,
                        ReservaEstado::DB_VALOR_PAGO_TOTAL
                    );
                    $nombre   = 'Completar Airbnb';

                    // Intentar extraer una URL desde la descripción (si existiera)
                    if (preg_match('~[a-z]+://\S+~', (string)($event->description ?? ''), $m)) {
                        $enlace = $m[0];
                    }
                } elseif ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $insertar = true;
                    // Booking: dejar en INICIAL para luego completar datos
                    $estado = $this->entityManager->getReference(
                        ReservaEstado::class,
                        ReservaEstado::DB_VALOR_INICIAL
                    );
                    // Limpiar "CLOSED - Not available" del summary, si aparece
                    $cleanSummary = preg_replace('/CLOSED\s*[–-]\s*Not available/i', '', $summaryRaw) ?? $summaryRaw;
                    $nombre       = trim($cleanSummary . ' Completar Booking');
                } elseif ($canal == ReservaChannel::DB_VALOR_VRBO) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(
                        ReservaEstado::class,
                        ReservaEstado::DB_VALOR_PAGO_TOTAL
                    );
                    // Limpiar "Reserved - " del summary, si existe
                    $nombre = trim(preg_replace('/^Reserved\s*[-–]\s*/i', '', $summaryRaw) . ' Completar VRBO');
                } else {
                    // Canal genérico / no reconocido
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(
                        ReservaEstado::class,
                        ReservaEstado::DB_VALOR_INICIAL
                    );
                    $nombre   = $summaryRaw ?? 'Reserva';
                }

                /**
                 * Si existe una reserva en BD con ese UID (extendido) + unidad + canal:
                 *   - Si es manual: no se toca.
                 *   - Si no es manual: se actualizan solo las fechas (día), manteniendo la hora.
                 */
                if (!empty($existentes)) {
                    foreach ($existentes as $existente) {
                        if ($existente->isManual()) {
                            $output->writeln(sprintf('[KEEP] Existe manual (UID:%s), no se toca.', $uidParaBD));
                            continue 2; // salta al siguiente evento iCal
                        }

                        $oldIni = $existente->getFechahorainicio();
                        $oldFin = $existente->getFechahorafin();
                        $changed = false;

                        // Actualizar día de inicio si cambió (manteniendo hora actual)
                        if ($oldIni->format('Ymd') !== $dtstartRaw) {
                            $currentStartTime = $oldIni->format('H:i');
                            $existente->setFechahorainicio(
                                \DateTime::createFromFormat($fmt, $dtstartRaw . ' ' . $currentStartTime, $tz)
                            );
                            $changed = true;
                        }

                        // Actualizar día de fin si cambió (manteniendo hora actual)
                        if ($oldFin->format('Ymd') !== $dtendRaw) {
                            $currentEndTime = $oldFin->format('H:i');
                            $existente->setFechahorafin(
                                \DateTime::createFromFormat($fmt, $dtendRaw . ' ' . $currentEndTime, $tz)
                            );
                            $changed = true;
                        }

                        // Log de actualización vs “no hubo cambios”
                        if ($changed) {
                            ++$totalActualizados;
                            $output->writeln(sprintf(
                                '<info>[UPDATE] UID:%s %s→%s (antes %s→%s)</info>',
                                $uidParaBD,
                                $existente->getFechahorainicio()->format('Y-m-d H:i'),
                                $existente->getFechahorafin()->format('Y-m-d H:i'),
                                $oldIni->format('Y-m-d H:i'),
                                $oldFin->format('Y-m-d H:i')
                            ));
                        } else {
                            $output->writeln(sprintf(
                                '[SKIP] Ya estaba igual (UID:%s %s→%s)',
                                $uidParaBD,
                                $oldIni->format('Y-m-d H:i'),
                                $oldFin->format('Y-m-d H:i')
                            ));
                        }
                    }
                    // Como ya se gestionó este UID, continuamos con el siguiente evento del iCal
                    continue;
                }

                // Si existe por fechas pero no por UID → solo advertimos (no tocamos)
                if (!empty($existentesPorFechas)) {
                    $output->writeln(sprintf(
                        '<comment>[WARN] Existe por fechas pero no por UID (UID:%s). Revisa proveedor.</comment>',
                        $uidParaBD
                    ));
                }

                /**
                 * Inserción de nueva reserva si las reglas del canal lo permiten
                 * (insertar == true).
                 */
                if ($insertar) {
                    $output->writeln(sprintf(
                        '<info>[INSERT]</info> Canal:%s | Unit:%s | UID:%s%s | %s → %s | "%s"',
                        $nexo->getChannel()->getNombre(),
                        (string)$unidad->getId(),
                        (string)$uid,
                        $uidTag ? (' [' . $uidTag . ']') : '',
                        $start->format('Y-m-d H:i'),
                        $end->format('Y-m-d H:i'),
                        $summaryRaw
                    ));

                    if (!$dryRun) {
                        $reserva = new ReservaReserva();
                        $reserva->setChannel($nexo->getChannel());
                        $reserva->setUnitnexo($nexo);
                        $reserva->setUnit($unidad);
                        $reserva->setEstado($estado);
                        $reserva->setManual(false);
                        $reserva->setNombre($nombre);
                        $reserva->setEnlace($enlace);
                        $reserva->setUid($uidParaBD);
                        $reserva->setFechahorainicio($start);
                        $reserva->setFechahorafin($end);

                        $this->entityManager->persist($reserva);
                    }
                    ++$totalInsertados;
                } else {
                    $output->writeln(sprintf(
                        '[SKIP] No se inserta (regla de canal/summary). UID:%s',
                        $uidParaBD
                    ));
                }
            } // fin foreach eventos iCal

            /**
             * 4) Cancelar / reactivar reservas actuales según presencia en el feed.
             *
             * Mecanismo:
             *   - Se construye una key:
             *       UID (extendido si existe) | YmdInicio | YmdFin
             *   - Si la key NO está en $uidsArray (feed):
             *       → Se asume que el evento fue eliminado del iCal → CANCELAR,
             *         siempre que no esté ya en CANCELADO y no sea manual.
             *   - Si la key SÍ está en $uidsArray pero la reserva está CANCELADA:
             *       → REACTIVAR (pasa a INICIAL) porque volvió a aparecer.
             */
            foreach ($currentReservas as $currentReserva) {
                // Aseguramos que todas las reservas tengan unitnexo seteado
                if (empty($currentReserva->getUnitnexo())) {
                    $output->writeln(sprintf(
                        '<comment>[FIX] Reserva id:%d sin unitnexo, estableciendo nexo:%d</comment>',
                        $currentReserva->getId(),
                        $nexo->getId()
                    ));
                    if (!$dryRun) {
                        $currentReserva->setUnitnexo($nexo);
                    }
                }

                // Las reservas manuales nunca se cancelan/reactivan automáticamente
                if ($currentReserva->isManual()) {
                    $output->writeln(sprintf(
                        '[KEEP] Manual id:%d, no se cancela/reactiva.',
                        $currentReserva->getId()
                    ));
                    continue;
                }

                // Clave para comparar contra $uidsArray (feed iCal)
                $key = ($currentReserva->getUid() ?: '')
                    . '|' . $currentReserva->getFechahorainicio()->format('Ymd')
                    . '|' . $currentReserva->getFechahorafin()->format('Ymd');

                // Si la key no está en el feed -> CANCELAR si no está ya cancelada
                if (!in_array($key, $uidsArray, true)) {
                    if ($currentReserva->getEstado()->getId() != ReservaEstado::DB_VALOR_CANCELADO) {
                        $output->writeln(sprintf(
                            '<info>[CANCEL]</info> %s: %s (id:%d)',
                            $currentReserva->getChannel()->getNombre(),
                            $currentReserva->getNombre(),
                            $currentReserva->getId()
                        ));
                        if (!$dryRun) {
                            $currentReserva->setEstado(
                                $this->entityManager->getReference(
                                    ReservaEstado::class,
                                    ReservaEstado::DB_VALOR_CANCELADO
                                )
                            );
                        }
                        ++$totalCancelados;
                    }
                }
                // Si la key está en el feed y la reserva está CANCELADA -> REACTIVAR
                elseif ($currentReserva->getEstado()->getId() == ReservaEstado::DB_VALOR_CANCELADO) {
                    $output->writeln(sprintf(
                        '<info>[REACTIVATE]</info> %s: %s (id:%d)',
                        $currentReserva->getChannel()->getNombre(),
                        $currentReserva->getNombre(),
                        $currentReserva->getId()
                    ));

                    if (!$dryRun) {
                        // Reactivar y dejar en INICIAL
                        $currentReserva->setEstado(
                            $this->entityManager->getReference(
                                ReservaEstado::class,
                                ReservaEstado::DB_VALOR_INICIAL
                            )
                        );
                        $currentReserva->setModificado($ahora);

                        // Ajustes extra específicos para Booking al reactivar
                        if ($currentReserva->getChannel()->getId() == ReservaChannel::DB_VALOR_BOOKING) {
                            $currentReserva->setNombre('Reactivado - ' . $currentReserva->getNombre());
                            $currentReserva->setEnlace(null);
                            $currentReserva->setTelefono(null);
                            $currentReserva->setNota(null);
                            $currentReserva->setCalificacion(null);
                            $currentReserva->setCantidadadultos(1);
                            $currentReserva->setCantidadninos(0);
                        }
                    }
                    ++$totalReactivados;
                }
            } // fin foreach currentReservas
        } // fin foreach nexos

        // 5) Persistir cambios si no es dry-run
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // 6) Resumen final en consola
        $output->writeln([
            '============',
            sprintf(
                'Eventos leídos: %d | Insertados: %d | Actualizados: %d | Cancelados: %d | Reactivados: %d',
                $totalEventosLeidos,
                $totalInsertados,
                $totalActualizados,
                $totalCancelados,
                $totalReactivados
            ),
            $dryRun
                ? '<comment>Dry-run: no se persistieron cambios.</comment>'
                : '<info>¡Cambios persistidos!</info>',
        ]);

        return Command::SUCCESS;
    }
}
