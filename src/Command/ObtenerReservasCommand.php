<?php
namespace App\Command;

use App\Entity\ReservaEstado;
use App\Entity\ReservaChannel;
use App\Entity\ReservaReserva;
use Doctrine\ORM\EntityManagerInterface;
use ICal\ICal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Versión depurable y con soporte de overbooking para Booking.
 *
 * - Soporta múltiples eventos Booking con el mismo UID base
 *   mediante UIDs extendidos UID#N:x
 * - NUNCA acorta/cambia fechas de una reserva existente
 *   para acomodar otra con las mismas fechas de entrada
 *   pero distinta fecha de salida.
 * - Reutiliza un UID extendido solo si la reserva en BD
 *   tiene exactamente las mismas fechas (inicio/fin).
 * - Si las fechas no coinciden, genera un UID#N:nuevo.
 * - Reactivados quedan en estado INICIAL.
 * - Creación Booking en INICIAL.
 * - Sin envío de correo por duplicados.
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
     *  - --dry-run  → no persiste cambios
     *  - --nexo-id  → procesa solo ese nexo
     */
    protected function configure(): void
    {
        $this
            ->setHelp('Lee los iCal de los nexos, crea/actualiza Reservas. Maneja overbooking de Booking creando UIDs extendidos #N:x.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecuta sin persistir cambios en BD.')
            ->addOption('nexo-id', null, InputOption::VALUE_REQUIRED, 'Procesa solo el nexo con este ID (para depurar).');
    }

    /** ===================== Helpers ===================== */

    /**
     * Extrae el índice N del patrón "UID#N:x".
     * Ejemplo: "abc#N:3" => 3
     */
    private function extractIndexFromExtendedUid(string $uid): ?int
    {
        if (preg_match('/#N:(\d+)$/', $uid, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Obtiene el UID base, quitando el sufijo "#N:x" si existe.
     * Ejemplo: "abc#N:3" => "abc"
     *          "abc"     => "abc"
     */
    private function getBaseUid(string $uid): string
    {
        return preg_replace('/#N:\d+$/', '', $uid);
    }

    /** ===================== Execute ===================== */

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tz = new \DateTimeZone('America/Lima');

        try {
            $ahora = new \DateTime('now', $tz);
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Excepción capturada al crear fecha actual: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $dryRun       = (bool)$input->getOption('dry-run');
        $nexoFilterId = $input->getOption('nexo-id');

        $output->writeln([
            sprintf('<info>%s</info> Iniciando proceso%s...', $ahora->format('Y-m-d H:i:s'), $dryRun ? ' (dry-run)' : ''),
            '============',
        ]);

        // 1) Obtener nexos
        $nexosRepo = $this->entityManager->getRepository("App\Entity\ReservaUnitnexo");
        if ($nexoFilterId) {
            $nexos = $nexosRepo->findBy(['id' => (int)$nexoFilterId]);
            if (!$nexos) {
                $output->writeln(sprintf('<comment>No se encontró el nexo con id=%s</comment>', $nexoFilterId));
                return Command::SUCCESS;
            }
            $output->writeln(sprintf('<comment>Filtrando por nexo id=%s</comment>', $nexoFilterId));
        } else {
            $nexos = $nexosRepo->findAll();
        }

        $totalEventosLeidos = 0;
        $totalInsertados    = 0;
        $totalActualizados  = 0;
        $totalCancelados    = 0;
        $totalReactivados   = 0;

        // Para Booking:
        // - Siguiente índice por grupo (UID base + unidad)
        // - Reservas existentes por fechas (para reusar el mismo UID#N:x solo
        //   cuando las fechas son exactamente las mismas).
        //
        // Claves:
        //   groupKey = baseUid|unitId
        //
        // bookingNextIndex[groupKey] = int
        // bookingExistingByDates[groupKey][YmdIni|YmdFin] = uidExtendido
        $bookingNextIndex       = [];
        $bookingExistingByDates = [];

        foreach ($nexos as $nexo) {
            if ($nexo->isDeshabilitado()) {
                $output->writeln(sprintf('→ Nexo %d deshabilitado, saltando.', $nexo->getId()));
                continue;
            }

            // iCal reader
            $ical = new ICal(false, [
                'defaultSpan'      => 2,
                'defaultTimeZone'  => 'America/Lima',
                'defaultWeekStart' => 'MO',
            ]);

            $from = (new \DateTimeImmutable('today', $tz))->setTime(0, 0);

            // Reservas actuales desde hoy
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

            $output->writeln(sprintf(
                '→ Nexo %d | Canal:%s | Unidad:%s | Enlace:%s | Reservas actuales:%d',
                $nexo->getId(),
                $nexo->getChannel()->getNombre(),
                method_exists($nexo->getUnit(), 'getNombre') ? $nexo->getUnit()->getNombre() : $nexo->getUnit()->getId(),
                $nexo->getEnlace(),
                count($currentReservas)
            ));

            $canal          = $nexo->getChannel()->getId(); // 2: Airbnb 3: Booking 4: VRBO (según enum)
            $unidad         = $nexo->getUnit();
            $establecimiento = $unidad->getEstablecimiento();

            // Inicializar estructuras Booking para este nexo/unidad
            foreach ($currentReservas as $currentReserva) {
                if ($currentReserva->getChannel()->getId() !== ReservaChannel::DB_VALOR_BOOKING) {
                    continue;
                }
                $uidActual = (string)$currentReserva->getUid();
                if ($uidActual === '') {
                    continue;
                }

                $baseUid = $this->getBaseUid($uidActual);
                $groupKey = $baseUid . '|' . (string)$unidad->getId();

                $iniYmd = $currentReserva->getFechahorainicio()->format('Ymd');
                $finYmd = $currentReserva->getFechahorafin()->format('Ymd');
                $fechaKey = $iniYmd . '|' . $finYmd;

                // Mapear fechas actuales de la reserva a su UID extendido
                if (!isset($bookingExistingByDates[$groupKey])) {
                    $bookingExistingByDates[$groupKey] = [];
                }
                $bookingExistingByDates[$groupKey][$fechaKey] = $uidActual;

                // Calcular siguiente índice para este grupo
                $idx = $this->extractIndexFromExtendedUid($uidActual);
                if ($idx === null) {
                    // Caso legacy sin #N:x → mínimo siguiente índice 1
                    if (!isset($bookingNextIndex[$groupKey])) {
                        $bookingNextIndex[$groupKey] = 1;
                    }
                } else {
                    if (!isset($bookingNextIndex[$groupKey]) || $idx + 1 > $bookingNextIndex[$groupKey]) {
                        $bookingNextIndex[$groupKey] = $idx + 1;
                    }
                }
            }

            // Si algún grupo Booking no tenía reservas previas, se inicializa al vuelo
            // la primera vez que aparezca en el feed.

            // Lista de eventKeys presentes en el iCal:
            //   eventKey = uidExtendido|YmdIni|YmdFin
            $uidsArray = [];

            // 2) Procesar eventos del iCal
            foreach ($ical->events() as $event) {
                ++$totalEventosLeidos;

                // UID base del evento
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

                $dtstartRaw = $event->dtstart ?? '';
                $dtendRaw   = $event->dtend ?? '';
                $summaryRaw = $event->summary ?? '';

                // Formato de fecha + hora
                $fmt      = 'Ymd H:i';
                $checkin  = $establecimiento->getCheckin()  ?? '14:00';
                $checkout = $establecimiento->getCheckout() ?? '10:00';

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

                $uidParaBD = $uid; // se ajusta para Booking si hace falta
                $uidTag    = '';

                // =========================
                //   LÓGICA BOOKING UID#N:x
                // =========================
                if ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $baseUid  = $uid;
                    $groupKey = $baseUid . '|' . (string)$unidad->getId();
                    $fechaKey = $dtstartRaw . '|' . $dtendRaw;

                    // Si no hay info previa para este grupo, inicializar
                    if (!isset($bookingExistingByDates[$groupKey])) {
                        $bookingExistingByDates[$groupKey] = [];
                    }
                    if (!isset($bookingNextIndex[$groupKey])) {
                        $bookingNextIndex[$groupKey] = 1;
                    }

                    // 1) ¿Existe ya una reserva Booking en BD con este
                    //    mismo baseUid + unidad + fechas?
                    //    → Reutilizar su UID extendido, sin cambiar fechas.
                    if (isset($bookingExistingByDates[$groupKey][$fechaKey])) {
                        $uidExtendido = $bookingExistingByDates[$groupKey][$fechaKey];
                        $uidParaBD    = $uidExtendido;
                        $idx          = $this->extractIndexFromExtendedUid($uidExtendido);
                        $uidTag       = $idx !== null ? ('N:' . $idx) : '';
                        // No cambiamos bookingNextIndex aquí, ya fue calculado antes.
                    } else {
                        // 2) No existe reserva con esas fechas para ese UID base:
                        //    → Generar un UID#N:nuevo, N tomado de bookingNextIndex.
                        $next = $bookingNextIndex[$groupKey];
                        $uidParaBD = $baseUid . '#N:' . $next;
                        $uidTag    = 'N:' . $next;

                        // Registrar en memoria este nuevo rango como perteneciente
                        // a este UID extendido. Así, si el feed repite exactamente
                        // el mismo evento en la misma ejecución, reutilizará este.
                        $bookingExistingByDates[$groupKey][$fechaKey] = $uidParaBD;

                        // Avanzar el índice
                        $bookingNextIndex[$groupKey] = $next + 1;
                    }
                }

                $eventKey = $uidParaBD . '|' . $dtstartRaw . '|' . $dtendRaw;

                if ($canal == ReservaChannel::DB_VALOR_BOOKING && in_array($eventKey, $uidsArray, true)) {
                    $output->writeln(sprintf('<comment>[DUP-FEED] Booking evento idéntico: %s</comment>', $eventKey));
                } else {
                    $uidsArray[] = $eventKey;
                }

                // Buscar reservas existentes por UID (ya sea extendido o base si no es Booking)
                $existentes = $this->entityManager->getRepository(ReservaReserva::class)->findBy([
                    'uid'     => $uidParaBD,
                    'unit'    => $unidad,
                    'channel' => $nexo->getChannel(),
                ]);

                // Compatibilidad hacia atrás: si es Booking y no existe con UID extendido,
                // se intenta migrar desde una reserva con el UID base + mismas fechas.
                $existentesPorFechas = [];
                if ($canal == ReservaChannel::DB_VALOR_BOOKING && empty($existentes)) {
                    $baseUid = $uid;
                    $existenteBase = $this->entityManager->getRepository(ReservaReserva::class)->findOneBy([
                        'uid'             => $baseUid,
                        'unit'            => $unidad,
                        'channel'         => $nexo->getChannel(),
                        'fechahorainicio' => $start,
                        'fechahorafin'    => $end,
                    ]);
                    if ($existenteBase) {
                        // Migrar a #N:1 si no tenía sufijo
                        $nuevoUid = $baseUid . '#N:1';
                        $output->writeln(sprintf(
                            '<comment>[MIGRATE]</comment> UID base "%s" → "%s" (id:%d)',
                            $existenteBase->getUid(),
                            $nuevoUid,
                            $existenteBase->getId()
                        ));
                        if (!$dryRun) {
                            $existenteBase->setUid($nuevoUid);
                        }
                        $uidParaBD  = $nuevoUid;
                        $existentes = [$existenteBase];

                        // Ajustar estructuras Booking en memoria
                        $groupKey = $baseUid . '|' . (string)$unidad->getId();
                        $fechaKey = $dtstartRaw . '|' . $dtendRaw;
                        $bookingExistingByDates[$groupKey][$fechaKey] = $nuevoUid;

                        $idx = $this->extractIndexFromExtendedUid($nuevoUid) ?? 1;
                        if (!isset($bookingNextIndex[$groupKey]) || $idx + 1 > $bookingNextIndex[$groupKey]) {
                            $bookingNextIndex[$groupKey] = $idx + 1;
                        }
                    }
                }

                // Fallback diagnóstico: buscar por fechas
                if (empty($existentes)) {
                    $existentesPorFechas = $this->entityManager->getRepository(ReservaReserva::class)->createQueryBuilder('r')
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

                // Decisiones por canal / summary
                $insertar = false;
                $estado   = null;
                $nombre   = '';
                $enlace   = '';

                if ($summaryRaw === 'Airbnb (Not available)') {
                    // Bloqueos "Not available" de Airbnb se ignoran
                    $insertar = false;
                    $output->writeln(sprintf('[SKIP] Airbnb Not available (UID:%s)', $uid));
                } elseif ($canal == ReservaChannel::DB_VALOR_AIRBNB) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_PAGO_TOTAL);
                    $nombre   = 'Completar Airbnb';

                    if (preg_match('~[a-z]+://\S+~', (string)($event->description ?? ''), $m)) {
                        $enlace = $m[0];
                    }
                } elseif ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL);
                    $cleanSummary = preg_replace('/CLOSED\s*[–-]\s*Not available/i', '', $summaryRaw) ?? $summaryRaw;
                    $nombre = trim($cleanSummary . ' Completar Booking');
                } elseif ($canal == ReservaChannel::DB_VALOR_VRBO) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_PAGO_TOTAL);
                    $nombre   = trim(preg_replace('/^Reserved\s*[-–]\s*/i', '', $summaryRaw) . ' Completar VRBO');
                } else {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL);
                    $nombre   = $summaryRaw ?? 'Reserva';
                }

                // Si ya existe por UID → actualizar fechas solo si cambian (y no es manual)
                if (!empty($existentes)) {
                    foreach ($existentes as $existente) {
                        if ($existente->isManual()) {
                            $output->writeln(sprintf('[KEEP] Existe manual (UID:%s), no se toca.', $uidParaBD));
                            continue 2; // siguiente evento
                        }

                        $oldIni = $existente->getFechahorainicio();
                        $oldFin = $existente->getFechahorafin();
                        $changed = false;

                        if ($oldIni->format('Ymd') !== $dtstartRaw) {
                            $currentStartTime = $oldIni->format('H:i');
                            $existente->setFechahorainicio(
                                \DateTime::createFromFormat($fmt, $dtstartRaw . ' ' . $currentStartTime, $tz)
                            );
                            $changed = true;
                        }
                        if ($oldFin->format('Ymd') !== $dtendRaw) {
                            $currentEndTime = $oldFin->format('H:i');
                            $existente->setFechahorafin(
                                \DateTime::createFromFormat($fmt, $dtendRaw . ' ' . $currentEndTime, $tz)
                            );
                            $changed = true;
                        }

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
                    continue;
                }

                // Si no existe por UID pero sí por fechas, solo warning
                if (!empty($existentesPorFechas)) {
                    $output->writeln(sprintf(
                        '<comment>[WARN] Existe por fechas pero no por UID (UID:%s). Revisa proveedor.</comment>',
                        $uidParaBD
                    ));
                }

                // Inserción de nueva reserva
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
            } // foreach events

            // 3) Cancelar/reactivar según presencia en el iCal
            foreach ($currentReservas as $currentReserva) {
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

                if ($currentReserva->isManual()) {
                    $output->writeln(sprintf(
                        '[KEEP] Manual id:%d, no se cancela/reactiva.',
                        $currentReserva->getId()
                    ));
                    continue;
                }

                $key = ($currentReserva->getUid() ?: '')
                    . '|' . $currentReserva->getFechahorainicio()->format('Ymd')
                    . '|' . $currentReserva->getFechahorafin()->format('Ymd');

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
                                $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_CANCELADO)
                            );
                        }
                        ++$totalCancelados;
                    }
                } elseif ($currentReserva->getEstado()->getId() == ReservaEstado::DB_VALOR_CANCELADO) {
                    $output->writeln(sprintf(
                        '<info>[REACTIVATE]</info> %s: %s (id:%d)',
                        $currentReserva->getChannel()->getNombre(),
                        $currentReserva->getNombre(),
                        $currentReserva->getId()
                    ));

                    if (!$dryRun) {
                        $currentReserva->setEstado(
                            $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL)
                        );
                        $currentReserva->setModificado($ahora);

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
            } // foreach currentReservas
        } // foreach nexos

        if (!$dryRun) {
            $this->entityManager->flush();
        }

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
