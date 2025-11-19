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
 * Versión depurable y con soporte de overbooking para Booking (UID#N:x).
 *
 * - Auto-migración de registros existentes (UID base -> UID#N:1)
 * - Reactivados quedan en estado INICIAL
 * - Creación de reservas Booking en INICIAL
 * - Soporta múltiples eventos Booking con el mismo UID base (cada uno con UID#N:x distinto)
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

    /**
     * Configuración del comando:
     *  - --dry-run: simula sin guardar en BD
     *  - --nexo-id: procesa solo un nexo específico (para debug)
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
     * Inicializa el "pool" de índices #N existentes para un grupo Booking
     * (mismo UID base + misma unidad + mismo canal), ignorando las fechas.
     *
     * Retorna:
     *   [ colaExistentes(array de "UID#N:x"), siguienteIndex(int) ]
     *
     * De esta forma:
     *   - Se puede tener más de una reserva Booking con el mismo UID base
     *   - Cada reserva obtiene un UID extendido único (UID#N:1, UID#N:2, ...)
     */
    private function initBookingIndexPool(
        EntityManagerInterface $em,
                               $unidad,
                               $canal,
        string $baseUid
    ): array {
        $repo = $em->getRepository(ReservaReserva::class);

        // Trae TODOS los UID#N:x de ese UID base para esa unidad+canal,
        // sin importar fechas. Esto permite múltiples reservas distintas
        // (p.ej. varios VEVENT con mismo UID pero diferentes rangos).
        $existentes = $repo->createQueryBuilder('r')
            ->where('r.unit = :unit')
            ->andWhere('r.channel = :channel')
            ->andWhere('r.uid LIKE :pattern')
            ->setParameter('unit', $unidad)
            ->setParameter('channel', $canal)
            ->setParameter('pattern', $baseUid . '#N:%')
            ->getQuery()
            ->getResult();

        $indices = [];
        foreach ($existentes as $r) {
            $idx = $this->extractIndexFromExtendedUid((string)$r->getUid());
            if ($idx !== null) {
                $indices[] = $idx;
            }
        }
        sort($indices);

        // Siguiente índice libre (#N:x)
        $siguiente = empty($indices) ? 1 : (max($indices) + 1);

        // Cola de UIDs ya existentes ordenados (#N:1, #N:2, ...)
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
            // Fecha/hora actual (mutable) para compatibilidad con entidades
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

        // 1) Obtener nexos (todos o uno filtrado)
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

        // Contadores globales de la ejecución
        $totalEventosLeidos   = 0;
        $totalInsertados      = 0;
        $totalActualizados    = 0;
        $totalCancelados      = 0;
        $totalReactivados     = 0;

        // Estado en memoria para Booking (por ejecución)
        // Claves por grupo: "uidBase|unitId"
        //
        // bookingPools:   groupKey => [colaExistentes[], siguienteIndex]
        // bookingClaimed: groupKey => set de UIDs extendidos ya “reclamados” en esta ejecución
        $bookingPools   = [];
        $bookingClaimed = [];

        foreach ($nexos as $nexo) {
            if ($nexo->isDeshabilitado()) {
                $output->writeln(sprintf('→ Nexo %d deshabilitado, saltando.', $nexo->getId()));
                continue;
            }

            // Instancia de ICal para leer el feed remoto
            $ical = new ICal(false, [
                'defaultSpan'      => 2,
                'defaultTimeZone'  => 'America/Lima',
                'defaultWeekStart' => 'MO',
            ]);

            // Desde hoy (00:00) en adelante
            $from = (new \DateTimeImmutable('today', $tz))->setTime(0, 0);

            // Reservas actuales en BD para este nexo+unidad+canal desde hoy
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

            // Cargar iCal remoto
            try {
                $ical->initUrl($nexo->getEnlace());
            } catch (\Exception $e) {
                $output->writeln(sprintf('<error>Excepción al leer iCal de nexo %d: %s</error>', $nexo->getId(), $e->getMessage()));
                continue;
            }

            $canal          = $nexo->getChannel()->getId(); // 2: Airbnb 3: Booking 4: VRBO (según tu enum)
            $unidad         = $nexo->getUnit();
            $establecimiento = $unidad->getEstablecimiento();

            // Arreglo de "eventKeys" para saber qué reservas siguen existiendo en el feed
            // Formato: uidExtendido|YmdIni|YmdFin
            $uidsArray = [];

            // 2) Recorrer eventos del iCal
            foreach ($ical->events() as $event) {
                ++$totalEventosLeidos;

                // UID base del evento; si viene vacío se genera uno de fallback
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

                // Parseo de fechas con hora de check-in / check-out del establecimiento
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

                // === Soporte de overbooking Booking: extiende UID a UID#N:x ===
                $uidParaBD = $uid; // será el UID que se usa finalmente en la BD
                $uidTag    = '';   // etiqueta de diagnóstico (N:x) para logs

                if ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    // Grupo por UID base + unidad (no por fechas),
                    // para poder tener múltiples reservas con mismo UID base
                    $groupKey = $uid . '|' . (string)$unidad->getId();

                    if (!isset($bookingPools[$groupKey])) {
                        // Inicializa el pool para este UID base + unidad + canal
                        [$colaExistentes, $siguiente] = $this->initBookingIndexPool(
                            $this->entityManager,
                            $unidad,
                            $nexo->getChannel(),
                            $uid
                        );
                        $bookingPools[$groupKey]   = [$colaExistentes, $siguiente];
                        $bookingClaimed[$groupKey] = [];
                    }

                    [$colaExistentes, $siguiente] = $bookingPools[$groupKey];

                    // 1) Intentar reusar un UID#N:x existente en BD que no haya sido "reclamado" en esta ejecución
                    $reusado = null;
                    while (!empty($colaExistentes)) {
                        $candidato = array_shift($colaExistentes);
                        if (!isset($bookingClaimed[$groupKey][$candidato])) {
                            $reusado = $candidato;
                            break;
                        }
                    }

                    if ($reusado) {
                        // Reutilizamos una reserva Booking ya existente (mismo UID#N:x)
                        $uidParaBD = $reusado;
                        $uidTag    = 'N:' . $this->extractIndexFromExtendedUid($reusado);
                    } else {
                        // 2) No hay UID#N:x libre -> generar uno nuevo
                        $uidParaBD = $uid . '#N:' . $siguiente;
                        $uidTag    = 'N:' . $siguiente;
                        $siguiente++;
                    }

                    // Guardar estado actualizado del pool y marcar el UID extendido como reclamado
                    $bookingPools[$groupKey]              = [$colaExistentes, $siguiente];
                    $bookingClaimed[$groupKey][$uidParaBD] = true;
                }

                // Clave única por evento (para detección de presencia / cancelación)
                $eventKey = $uidParaBD . '|' . $dtstartRaw . '|' . $dtendRaw;

                if (in_array($eventKey, $uidsArray, true) && $canal == ReservaChannel::DB_VALOR_BOOKING) {
                    // Si el feed repite EXACTAMENTE el mismo UID extendido + mismas fechas
                    // en la misma ejecución, solo lo registramos como diagnóstico.
                    $output->writeln(sprintf('<comment>[DUP-FEED] Booking evento idéntico: %s</comment>', $eventKey));
                    // No se hace "continue": se sigue procesando normalmente.
                } else {
                    $uidsArray[] = $eventKey;
                }

                // Buscar reservas existentes en BD por UID extendido (uidParaBD) + unidad + canal
                $existentes = $this->entityManager->getRepository(ReservaReserva::class)->findBy([
                    'uid'     => $uidParaBD,
                    'unit'    => $unidad,
                    'channel' => $nexo->getChannel(),
                ]);

                // === COMPATIBILIDAD hacia atrás (solo Booking):
                // Si no existe con UID extendido, intenta encontrar una reserva
                // antigua por UID base y mismas fechas → la migra a "#N:1".
                $existentesPorFechas = [];
                if ($canal == ReservaChannel::DB_VALOR_BOOKING && empty($existentes)) {
                    $existenteBase = $this->entityManager->getRepository(ReservaReserva::class)->findOneBy([
                        'uid'             => $uid, // uid base (sin #N:x)
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
                        // Lo tratamos como existente con el nuevo UID extendido
                        $uidParaBD  = $nuevoUid;
                        $existentes = [$existenteBase];
                    }
                }

                // Fallback diagnóstico: si no se encontró por UID, se intenta por fechas
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

                // Decidir inserción / actualización y estado inicial
                $insertar = false;
                $estado   = null;
                $nombre   = '';
                $enlace   = '';

                // Reglas por canal/summary
                if ($summaryRaw === 'Airbnb (Not available)') {
                    // Bloqueos "Not available" de Airbnb se ignoran
                    $insertar = false;
                    $output->writeln(sprintf('[SKIP] Airbnb Not available (UID:%s)', $uid));
                } elseif ($canal == ReservaChannel::DB_VALOR_AIRBNB) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_PAGO_TOTAL);
                    $nombre   = 'Completar Airbnb';

                    // Intentar sacar un enlace de la descripción (URL)
                    if (preg_match('~[a-z]+://\S+~', (string)($event->description ?? ''), $m)) {
                        $enlace = $m[0];
                    }
                } elseif ($canal == ReservaChannel::DB_VALOR_BOOKING) {
                    $insertar = true;
                    // Reservas Booking se crean en INICIAL para que luego se completen
                    $estado = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL);

                    // Normalizar "CLOSED - Not available" vs "CLOSED – Not available"
                    $cleanSummary = preg_replace('/CLOSED\s*[–-]\s*Not available/i', '', $summaryRaw) ?? $summaryRaw;
                    $nombre       = trim($cleanSummary . ' Completar Booking');
                } elseif ($canal == ReservaChannel::DB_VALOR_VRBO) {
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_PAGO_TOTAL);
                    // Quitar prefijo "Reserved - " del summary si existe
                    $nombre = trim(preg_replace('/^Reserved\s*[-–]\s*/i', '', $summaryRaw) . ' Completar VRBO');
                } else {
                    // Canal genérico/desconocido
                    $insertar = true;
                    $estado   = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_INICIAL);
                    $nombre   = $summaryRaw ?? 'Reserva';
                }

                // Si ya existe por UID (extendido) => actualizar fechas (si cambiaron)
                if (!empty($existentes)) {
                    foreach ($existentes as $existente) {
                        if ($existente->isManual()) {
                            // Reservas manuales nunca se tocan automáticamente
                            $output->writeln(sprintf('[KEEP] Existe manual (UID:%s), no se toca.', $uidParaBD));
                            continue 2; // salta al siguiente evento iCal
                        }

                        $oldIni = $existente->getFechahorainicio();
                        $oldFin = $existente->getFechahorafin();

                        $changed = false;

                        // Solo cambiamos la fecha (Ymd), conservando la hora actual
                        if ($oldIni->format('Ymd') !== $dtstartRaw) {
                            $currentStartTime = $oldIni->format('H:i');
                            $existente->setFechahorainicio(\DateTime::createFromFormat($fmt, $dtstartRaw . ' ' . $currentStartTime, $tz));
                            $changed = true;
                        }
                        if ($oldFin->format('Ymd') !== $dtendRaw) {
                            $currentEndTime = $oldFin->format('H:i');
                            $existente->setFechahorafin(\DateTime::createFromFormat($fmt, $dtendRaw . ' ' . $currentEndTime, $tz));
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
                    continue; // ya se gestionó este evento por UID
                }

                // Si no existe por UID pero sí por fechas -> solo warning (no se toca)
                if (!empty($existentesPorFechas)) {
                    $output->writeln(sprintf(
                        '<comment>[WARN] Existe por fechas pero no por UID (UID:%s). Revisa proveedor.</comment>',
                        $uidParaBD
                    ));
                }

                // Inserción de nueva reserva, si aplica por reglas de canal/summary
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

            // 3) Cancelar / reactivar reservas actuales según la presencia en el feed
            foreach ($currentReservas as $currentReserva) {
                // Fix: asegurar que todas tengan unitnexo asociado
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
                    // Las manuales no se cancelan/reactivan automáticamente
                    $output->writeln(sprintf(
                        '[KEEP] Manual id:%d, no se cancela/reactiva.',
                        $currentReserva->getId()
                    ));
                    continue;
                }

                // eventKey generada con el UID (extendido si lo tiene) + fechas Ymd
                $key = ($currentReserva->getUid() ?: '')
                    . '|' . $currentReserva->getFechahorainicio()->format('Ymd')
                    . '|' . $currentReserva->getFechahorafin()->format('Ymd');

                // Si no está presente en el feed -> cancelar
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
                    // Si aparece otra vez en el feed y estaba cancelada -> reactivar a INICIAL
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

                        // Limpiezas opcionales específicas para Booking
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

        // Envío de correo eliminado a propósito.

        return Command::SUCCESS;
    }
}
