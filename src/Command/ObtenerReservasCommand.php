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
 * Version depurable y con soporte de overbooking Booking (UID#N:x).
 * - Auto-migración de registros existentes (UID base -> UID#N:1)
 * - Reactivados quedan en estado INICIAL
 * - Creación Booking en INICIAL
 * - Se elimina el envío de correo de "duplicados booking"
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

    protected function configure(): void
    {
        $this
            ->setHelp('Lee los iCal de los nexos, crea/actualiza Reservas. Maneja overbooking de Booking creando UIDs extendidos #N:x.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecuta sin persistir cambios en BD.')
            ->addOption('nexo-id', null, InputOption::VALUE_REQUIRED, 'Procesa solo el nexo con este ID (para depurar).');
    }

    /** ===================== Helpers Overbooking ===================== */

    private function extractIndexFromExtendedUid(string $uid): ?int
    {
        if (preg_match('/#N:(\d+)$/', $uid, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Inicializa el "pool" de índices #N existentes para un grupo Booking
     * (uid base + mismas fechas + misma unidad/canal) y devuelve:
     *   [ colaExistentes(array de "UID#N:x"), siguienteIndex(int) ]
     */
    private function initBookingIndexPool(
        EntityManagerInterface $em,
                               $unidad,
                               $canal,
        \DateTime $start,
        \DateTime $end,
        string $baseUid
    ): array {
        $repo = $em->getRepository(ReservaReserva::class);

        $existentes = $repo->createQueryBuilder('r')
            ->where('r.unit = :unit')
            ->andWhere('r.channel = :channel')
            ->andWhere('r.fechahorainicio = :ini')
            ->andWhere('r.fechahorafin = :fin')
            ->andWhere('r.uid LIKE :pattern') // UIDs ya extendidos con #N:
            ->setParameter('unit', $unidad)
            ->setParameter('channel', $canal)
            ->setParameter('ini', $start)
            ->setParameter('fin', $end)
            ->setParameter('pattern', $baseUid . '#N:%')
            ->getQuery()
            ->getResult();

        $indices = [];
        foreach ($existentes as $r) {
            $idx = $this->extractIndexFromExtendedUid((string)$r->getUid());
            if ($idx !== null) { $indices[] = $idx; }
        }
        sort($indices);

        $siguiente = empty($indices) ? 1 : (max($indices) + 1);

        $colaExistentes = [];
        foreach ($indices as $idx) {
            $colaExistentes[] = $baseUid . '#N:' . $idx;
        }

        return [$colaExistentes, $siguiente];
    }

    /** ===================== Execute ===================== */

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tz = new \DateTimeZone('America/Lima');

        try {
            // Mutable para compatibilidad con entidades que esperan \DateTime
            $ahora = new \DateTime('now', $tz);
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Excepción capturada al crear fecha actual: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $dryRun = (bool)$input->getOption('dry-run');
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
        $totalInsertados = 0;
        $totalActualizados = 0;
        $totalCancelados = 0;
        $totalReactivados = 0;

        // Estado en memoria para Booking (por ejecución)
        // Claves por grupo: "uidBase|Ymdini|Ymdfin|unitId"
        $bookingPools = [];   // groupKey => [colaExistentes[], siguienteIndex]
        $bookingClaimed = []; // groupKey => set de UIDs ya “reclamados” en esta ejecución

        foreach ($nexos as $nexo) {
            if ($nexo->isDeshabilitado()) {
                $output->writeln(sprintf('→ Nexo %d deshabilitado, saltando.', $nexo->getId()));
                continue;
            }

            $ical = new ICal(false, [
                'defaultSpan'      => 2,
                'defaultTimeZone'  => 'America/Lima',
                'defaultWeekStart' => 'MO',
            ]);

            $from = (new \DateTimeImmutable('today', $tz))->setTime(0, 0);

            // Reservas actuales (desde hoy)
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

            // Cargar iCal
            try {
                $ical->initUrl($nexo->getEnlace());
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Excepción al leer iCal de nexo %d: %s</error>', $nexo->getId(), $e->getMessage()));
                continue;
            }

            $canal = $nexo->getChannel()->getId(); // 2: Airbnb 3: Booking 4: VRBO (según tu enum)
            $unidad = $nexo->getUnit();
            $establecimiento = $unidad->getEstablecimiento();

            $uidsArray = []; // lista de eventKeys (uidExtendido|dtstart|dtend)

            foreach ($ical->events() as $event) {
                ++$totalEventosLeidos;

                // UID base
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

                // Parseo robusto de fechas (VALUE=DATE: Ymd)
                $fmt = 'Ymd H:i';
                $checkin  = $establecimiento->getCheckin()  ?? '14:00';
                $checkout = $establecimiento->getCheckout() ?? '10:00';

                $start = \DateTime::createFromFormat($fmt, $dtstartRaw.' '.$checkin, $tz);
                $end   = \DateTime::createFromFormat($fmt, $dtendRaw.' '.$checkout, $tz);

                if (!$start || !$end) {
                    $output->writeln(sprintf('<error>[SKIP] No pude parsear fechas: "%s %s" / "%s %s" (UID:%s)</error>', $dtstartRaw, $checkin, $dtendRaw, $checkout, $uid));
                    continue;
                }

                // === Soporte de overbooking Booking: extiende UID a UID#N:x ===
                $uidParaBD = $uid;
                $uidTag    = '';

                if ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $groupKey = $uid.'|'.$dtstartRaw.'|'.$dtendRaw.'|'.(string)$unidad->getId();

                    if (!isset($bookingPools[$groupKey])) {
                        [$colaExistentes, $siguiente] = $this->initBookingIndexPool(
                            $this->entityManager, $unidad, $nexo->getChannel(), $start, $end, $uid
                        );
                        $bookingPools[$groupKey] = [$colaExistentes, $siguiente];
                        $bookingClaimed[$groupKey] = [];
                    }

                    [$colaExistentes, $siguiente] = $bookingPools[$groupKey];

                    // 1) Reusar un #N existente en BD que no se haya reclamado aún en esta corrida
                    $reusado = null;
                    while (!empty($colaExistentes)) {
                        $candidato = array_shift($colaExistentes);
                        if (!isset($bookingClaimed[$groupKey][$candidato])) {
                            $reusado = $candidato;
                            break;
                        }
                    }

                    if ($reusado) {
                        $uidParaBD = $reusado;
                        $uidTag = 'N:' . $this->extractIndexFromExtendedUid($reusado);
                    } else {
                        // 2) Sin #N libre -> asigna el siguiente
                        $uidParaBD = $uid . '#N:' . $siguiente;
                        $uidTag = 'N:' . $siguiente;
                        $siguiente++;
                    }

                    // Actualiza estructuras de ejecución
                    $bookingPools[$groupKey] = [$colaExistentes, $siguiente];
                    $bookingClaimed[$groupKey][$uidParaBD] = true;
                }

                // Clave de presencia/cancelación usa SIEMPRE el UID extendido si aplica
                $eventKey = $uidParaBD.'|'.$dtstartRaw.'|'.$dtendRaw;

                if (in_array($eventKey, $uidsArray, true) && $canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $output->writeln(sprintf('<comment>[DUP-FEED] Booking evento idéntico: %s</comment>', $eventKey));
                } else {
                    $uidsArray[] = $eventKey;
                }

                // Buscar existentes por UID extendido
                $existentes = $this->entityManager->getRepository(ReservaReserva::class)->findBy([
                    'uid'     => $uidParaBD,
                    'unit'    => $unidad,
                    'channel' => $nexo->getChannel(),
                ]);

                // === COMPATIBILIDAD hacia atrás (solo Booking):
                // si no existe con UID extendido, intenta con UID base y mismas fechas → migra a #N:1
                if ($canal == ReservaChannel::DB_VALOR_BOOKING && empty($existentes)) {
                    $existenteBase = $this->entityManager->getRepository(ReservaReserva::class)->findOneBy([
                        'uid'              => $uid,      // uid base (sin #N:x)
                        'unit'             => $unidad,
                        'channel'          => $nexo->getChannel(),
                        'fechahorainicio'  => $start,
                        'fechahorafin'     => $end,
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
                        // Forzamos a tratarlo como existente con el nuevo UID
                        $uidParaBD = $nuevoUid;
                        $existentes = [$existenteBase];
                    }
                }

                // Fallback diagnóstico por fechas exactas (si hiciera falta)
                $existentesPorFechas = [];
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
                        $output->writeln(sprintf('<comment>[INFO] Encontrado por fechas (no por UID) UID:%s %s→%s</comment>', $uidParaBD, $start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')));
                    }
                }

                // Decidir inserción/actualización
                $insertar = false;
                $estado   = null;
                $nombre   = '';
                $enlace   = '';

                // Rama por canal
                if ($summaryRaw === 'Airbnb (Not available)') {
                    // Airbnb "Not available" se ignora
                    $insertar = false;
                    $output->writeln(sprintf('[SKIP] Airbnb Not available (UID:%s)', $uid));
                } elseif ($canal == ReservaChannel::DB_VALOR_AIRBNB) {
                    $insertar = true;
                    $estado = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_PAGO_TOTAL);
                    $nombre = 'Completar Airbnb';
                    if (preg_match('~[a-z]+://\S+~', (string)($event->description ?? ''), $m)) {
                        $enlace = $m[0];
                    }
                } elseif ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $insertar = true;
                    // ✅ Cambio solicitado: creación Booking en INICIAL
                    $estado = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL);
                    // Normalizar "CLOSED - Not available" vs "CLOSED – Not available"
                    $cleanSummary = preg_replace('/CLOSED\s*[–-]\s*Not available/i', '', $summaryRaw) ?? $summaryRaw;
                    $nombre = trim($cleanSummary.' Completar Booking');
                } elseif ($canal == ReservaChannel::DB_VALOR_VRBO) {
                    $insertar = true;
                    $estado = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_PAGO_TOTAL);
                    $nombre = trim(preg_replace('/^Reserved\s*[-–]\s*/i', '', $summaryRaw).' Completar VRBO');
                } else {
                    $insertar = true;
                    $estado = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL);
                    $nombre = $summaryRaw ?? 'Reserva';
                }

                // Si ya existe por UID (misma unit+channel): actualizar fechas y seguir
                if (!empty($existentes)) {
                    foreach ($existentes as $existente) {
                        if ($existente->isManual()) {
                            $output->writeln(sprintf('[KEEP] Existe manual (UID:%s), no se toca.', $uidParaBD));
                            continue 2; // saltar este evento
                        }

                        $oldIni = $existente->getFechahorainicio();
                        $oldFin = $existente->getFechahorafin();

                        $changed = false;

                        if ($oldIni->format('Ymd') !== $dtstartRaw) {
                            $currentStartTime = $oldIni->format('H:i');
                            $existente->setFechahorainicio(\DateTime::createFromFormat($fmt, $dtstartRaw.' '.$currentStartTime, $tz));
                            $changed = true;
                        }
                        if ($oldFin->format('Ymd') !== $dtendRaw) {
                            $currentEndTime = $oldFin->format('H:i');
                            $existente->setFechahorafin(\DateTime::createFromFormat($fmt, $dtendRaw.' '.$currentEndTime, $tz));
                            $changed = true;
                        }

                        if ($changed) {
                            ++$totalActualizados;
                            $output->writeln(sprintf('<info>[UPDATE] UID:%s %s→%s (antes %s→%s)</info>',
                                $uidParaBD,
                                $existente->getFechahorainicio()->format('Y-m-d H:i'),
                                $existente->getFechahorafin()->format('Y-m-d H:i'),
                                $oldIni->format('Y-m-d H:i'),
                                $oldFin->format('Y-m-d H:i')
                            ));
                        } else {
                            $output->writeln(sprintf('[SKIP] Ya estaba igual (UID:%s %s→%s)', $uidParaBD, $oldIni->format('Y-m-d H:i'), $oldFin->format('Y-m-d H:i')));
                        }
                    }
                    continue;
                }

                // Si no existe por UID pero existe por fechas, solo log informativo
                if (!empty($existentesPorFechas)) {
                    $output->writeln(sprintf('<comment>[WARN] Existe por fechas pero no por UID (UID:%s). Revisa proveedor.</comment>', $uidParaBD));
                }

                if ($insertar) {
                    $output->writeln(sprintf(
                        '<info>[INSERT]</info> Canal:%s | Unit:%s | UID:%s%s | %s → %s | "%s"',
                        $nexo->getChannel()->getNombre(),
                        (string)$unidad->getId(),
                        (string)$uid,
                        $uidTag ? (' ['.$uidTag.']') : '',
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
                    $output->writeln(sprintf('[SKIP] No se inserta (regla de canal/summary). UID:%s', $uidParaBD));
                }
            } // foreach events

            // Cancelar/reactivar según presencia
            foreach ($currentReservas as $currentReserva) {
                // Ajuste de unitnexo vacío
                if (empty($currentReserva->getUnitnexo())) {
                    $output->writeln(sprintf('<comment>[FIX] Reserva id:%d sin unitnexo, estableciendo nexo:%d</comment>', $currentReserva->getId(), $nexo->getId()));
                    if (!$dryRun) {
                        $currentReserva->setUnitnexo($nexo);
                    }
                }

                if ($currentReserva->isManual()) {
                    $output->writeln(sprintf('[KEEP] Manual id:%d, no se cancela/reactiva.', $currentReserva->getId()));
                    continue;
                }

                // Comparamos contra eventKey con UID extendido si lo tuviera
                $key = ($currentReserva->getUid() ?: '')
                    .'|'.$currentReserva->getFechahorainicio()->format('Ymd')
                    .'|'.$currentReserva->getFechahorafin()->format('Ymd');

                if (!in_array($key, $uidsArray, true)) {
                    if ($currentReserva->getEstado()->getId() != ReservaEstado::DB_VALOR_CANCELADO) {
                        $output->writeln(sprintf('<info>[CANCEL]</info> %s: %s (id:%d)',
                            $currentReserva->getChannel()->getNombre(),
                            $currentReserva->getNombre(),
                            $currentReserva->getId()
                        ));
                        if (!$dryRun) {
                            $currentReserva->setEstado($this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_CANCELADO));
                        }
                        ++$totalCancelados;
                    }
                } elseif ($currentReserva->getEstado()->getId() == ReservaEstado::DB_VALOR_CANCELADO) {
                    $output->writeln(sprintf('<info>[REACTIVATE]</info> %s: %s (id:%d)',
                        $currentReserva->getChannel()->getNombre(),
                        $currentReserva->getNombre(),
                        $currentReserva->getId()
                    ));

                    if (!$dryRun) {
                        // ✅ Reactivados pasan a INICIAL
                        $currentReserva->setEstado($this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL));
                        $currentReserva->setModificado($ahora);

                        // Limpiezas opcionales para Booking:
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
            sprintf('Eventos leídos: %d | Insertados: %d | Actualizados: %d | Cancelados: %d | Reactivados: %d',
                $totalEventosLeidos, $totalInsertados, $totalActualizados, $totalCancelados, $totalReactivados),
            $dryRun ? '<comment>Dry-run: no se persistieron cambios.</comment>' : '<info>¡Cambios persistidos!</info>',
        ]);

        // ⛔ Envío de correo eliminado.

        return Command::SUCCESS;
    }
}
