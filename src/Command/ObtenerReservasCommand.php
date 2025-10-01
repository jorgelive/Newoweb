<?php
namespace App\Command;

use App\Entity\ReservaEstado;
use App\Entity\ReservaChannel;
use App\Entity\ReservaReserva;
use Doctrine\ORM\EntityManagerInterface;
use ICal\ICal;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;

/**
 * Debuggable version of ObtenerReservasCommand
 */
#[AsCommand(
    name: 'app:obtener-reservas',
    description: 'Obtiene las reservas desde iCal (Airbnb/Booking/VRBO) con logs de debug.'
)]
class ObtenerReservasCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private TransportInterface $mailer;
    private ParameterBagInterface $params;

    public function __construct(
        EntityManagerInterface $entityManager,
        TransportInterface $mailer,
        ParameterBagInterface $params
    ) {
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->params = $params;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Este comando lee los iCal de los nexos, crea/actualiza Reservas y muestra logs de diagnóstico.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecuta sin persistir cambios en BD.')
            ->addOption('nexo-id', null, InputOption::VALUE_REQUIRED, 'Procesa solo el nexo con este ID (para depurar).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tz = new \DateTimeZone('America/Lima');

        try {
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

        // 1) Obtener nexos (con filtro opcional)
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

        $duplicadosBooking = [];
        $totalEventosLeidos = 0;
        $totalInsertados = 0;
        $totalActualizados = 0;
        $totalCancelados = 0;
        $totalReactivados = 0;

        foreach ($nexos as $nexo) {
            if ($nexo->isDeshabilitado()) {
                $output->writeln(sprintf('→ Nexo %d deshabilitado, saltando.', $nexo->getId()));
                continue;
            }

            $ical = new ICal(false, [
                'defaultSpan'      => 2,
                'defaultTimeZone'  => 'America/Lima', // más seguro que 'UTC-5'
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

            $uidsArray = []; // ahora será de eventKeys (uid|dtstart|dtend)

            foreach ($ical->events() as $event) {
                ++$totalEventosLeidos;

                // Asegurar UID estable
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

                $eventKey = $uid.'|'.$dtstartRaw.'|'.$dtendRaw;

                // Duplicado en el feed (mismo UID y fecha) → solo informe
                if (in_array($eventKey, $uidsArray, true) && $nexo->getChannel()->getId() == ReservaChannel::DB_VALOR_BOOKING) {
                    $output->writeln(sprintf('<comment>[DUP-FEED] Booking repite %s (%s → %s) en el mismo iCal</comment>', $uid, $dtstartRaw, $dtendRaw));
                    $duplicadosBooking[] = [
                        'uid'   => $uid,
                        'inicio'=> new \DateTime($dtstartRaw.' 00:00:00', $tz),
                        'unidad'=> $unidad,
                    ];
                    // seguimos looping
                } else {
                    $uidsArray[] = $eventKey;
                }

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

                // Buscar existentes por UID+Unit+Channel
                $existentes = $this->entityManager->getRepository(ReservaReserva::class)->findBy([
                    'uid'     => $uid,
                    'unit'    => $unidad,
                    'channel' => $nexo->getChannel(),
                ]);

                // Fallback diagnóstico: por fechas exactas (a veces el proveedor cambia UID)
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
                        $output->writeln(sprintf('<comment>[INFO] Encontrado por fechas (no por UID) UID:%s %s→%s</comment>', $uid, $start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')));
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
                    $estado = $this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_CONFIRMADO);
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
                            $output->writeln(sprintf('[KEEP] Existe manual (UID:%s), no se toca.', $uid));
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
                                $uid,
                                $existente->getFechahorainicio()->format('Y-m-d H:i'),
                                $existente->getFechahorafin()->format('Y-m-d H:i'),
                                $oldIni->format('Y-m-d H:i'),
                                $oldFin->format('Y-m-d H:i')
                            ));
                        } else {
                            $output->writeln(sprintf('[SKIP] Ya estaba igual (UID:%s %s→%s)', $uid, $oldIni->format('Y-m-d H:i'), $oldFin->format('Y-m-d H:i')));
                        }
                    }
                    continue;
                }

                // Si no existe por UID pero existe por fechas, solo log informativo (tú decides si fusionar)
                if (!empty($existentesPorFechas)) {
                    $output->writeln(sprintf('<comment>[WARN] Existe por fechas pero no por UID (UID:%s). Revisa proveedor.</comment>', $uid));
                    // si quisieras, aquí podrías actualizar ese registro con el nuevo UID
                }

                if ($insertar) {
                    $output->writeln(sprintf(
                        '<info>[INSERT]</info> Canal:%s | Unit:%s | UID:%s | %s → %s | "%s"',
                        $nexo->getChannel()->getNombre(),
                        (string)$unidad->getId(),
                        (string)$uid,
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
                        $reserva->setUid($uid);
                        $reserva->setFechahorainicio($start);
                        $reserva->setFechahorafin($end);
                        $this->entityManager->persist($reserva);
                    }
                    ++$totalInsertados;
                } else {
                    $output->writeln(sprintf('[SKIP] No se inserta (regla de canal/summary). UID:%s', $uid));
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

                // Ojo: ahora comparamos contra eventKey; reconstruimos las tres piezas
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
                        if ($currentReserva->getChannel()->getId() == ReservaChannel::DB_VALOR_BOOKING) {
                            $currentReserva->setEstado($this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_CONFIRMADO));
                            $currentReserva->setNombre('Reactivado - ' . $currentReserva->getNombre());
                            $currentReserva->setEnlace(null);
                            $currentReserva->setTelefono(null);
                            $currentReserva->setNota(null);
                            $currentReserva->setCalificacion(null);
                            $currentReserva->setCantidadadultos(1);
                            $currentReserva->setCantidadninos(0);
                            $currentReserva->setCreado($ahora);
                            $currentReserva->setModificado($ahora);
                        } else {
                            $currentReserva->setEstado($this->entityManager->getReference(ReservaEstado::class, ReservaEstado::DB_VALOR_CONFIRMADO));
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

        // Email de duplicados booking (misma lógica que tenías, respetando franja)
        if (!empty($duplicadosBooking)
            && (int)$ahora->format('H') == 15
            && (int)$ahora->format('i') > 30
            && (int)$ahora->format('i') < 40
            && !$dryRun
        ) {
            $email = (new TemplatedEmail())
                ->from(new Address($this->params->get('mailer_sender_email'), $this->params->get('mailer_sender_name')))
                ->subject('Alerta: Reserva de booking con el mismo UID')
                ->htmlTemplate('emails/command_obtener_reservas_booking_duplicados.html.twig')
                ->context([
                    'fechaHoraActual' => $ahora,
                    'duplicados' => $duplicadosBooking
                ]);

            $receivers = explode(',', $this->params->get('mailer_alert_receivers'));
            foreach ($receivers as $key => $receiver) {
                if ($key === array_key_first($receivers)) {
                    $email->to(new Address($receiver));
                } else {
                    $email->addTo(new Address($receiver));
                }
            }

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                $output->writeln('<error>No se ha podido enviar el email de duplicados.</error>');
                // no abortamos
            }
        }

        return Command::SUCCESS;
    }
}
