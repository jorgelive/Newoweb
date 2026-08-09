<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconcilia `msg_conversation.unread_count` con los mensajes que de verdad
 * están sin leer.
 *
 * ¿Por qué hace falta?: el contador está desnormalizado —se incrementa al
 * insertar cada mensaje entrante— y por tanto puede DERIVAR de la realidad. Y
 * derivó: los persisters de Beds24 y WhatsApp llamaban a `incrementUnreadCount()`
 * además de hacerlo `Message::onPrePersist()`, así que subía de dos en dos. En
 * producción una conversación marcaba 24 no leídos con 12 mensajes reales.
 *
 * El duplicado ya está corregido, pero los contadores viejos siguen inflados y
 * ningún flujo los recalcula solo. Este comando es la red de seguridad: se puede
 * volver a correr cuando se sospeche de una desviación, y es idempotente.
 *
 * La verdad es `direction = incoming AND status = received`: es exactamente lo
 * que `MarkConversationReadController` pasa a `read` al abrir un chat.
 */
#[AsCommand(
    name: 'app:message:recalcular-no-leidos',
    description: 'Recalcula el contador de no leídos de cada conversación a partir de sus mensajes.'
)]
final class RecalcularNoLeidosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Muestra lo que cambiaría sin escribir nada en la base de datos.'
            )
            ->addOption(
                'marcar-leidas-antes-de',
                null,
                InputOption::VALUE_REQUIRED,
                'Marca como leídos los mensajes entrantes de conversaciones CERRADAS o ARCHIVADAS cuyo último mensaje sea anterior a esta fecha (YYYY-MM-DD). Sirve para limpiar pendientes viejos que nadie va a contestar.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limpiarAntesDe = $input->getOption('marcar-leidas-antes-de');

        if ($dryRun) {
            $io->warning('DRY-RUN: no se escribirá nada.');
        }

        // ------------------------------------------------------------------
        // 1) Limpieza opcional de pendientes viejos.
        // ------------------------------------------------------------------
        if (is_string($limpiarAntesDe) && $limpiarAntesDe !== '') {
            $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $limpiarAntesDe);
            if ($fecha === false) {
                $io->error("Fecha inválida: «{$limpiarAntesDe}». Se espera YYYY-MM-DD.");
                return Command::FAILURE;
            }

            $viejas = $this->em->getRepository(MessageConversation::class)
                ->createQueryBuilder('c')
                ->where('c.status IN (:estados)')
                ->andWhere('c.lastMessageAt < :fecha')
                ->andWhere('c.unreadCount > 0')
                ->setParameter('estados', [MessageConversation::STATUS_CLOSED, MessageConversation::STATUS_ARCHIVED])
                ->setParameter('fecha', $fecha)
                ->getQuery()
                ->getResult();

            $io->section(sprintf('Conversaciones cerradas/archivadas anteriores a %s: %d', $fecha->format('Y-m-d'), count($viejas)));

            foreach ($viejas as $conversacion) {
                $pendientes = $this->em->getRepository(Message::class)->findBy([
                    'conversation' => $conversacion,
                    'direction'    => Message::DIRECTION_INCOMING,
                    'status'       => Message::STATUS_RECEIVED,
                ]);

                $io->writeln(sprintf(
                    '  · %s (%s) — %d mensajes pasarían a leídos',
                    $conversacion->getGuestName() ?? 'Sin nombre',
                    $conversacion->getStatus(),
                    count($pendientes)
                ));

                if (!$dryRun) {
                    foreach ($pendientes as $mensaje) {
                        $mensaje->setStatus(Message::STATUS_READ);
                    }
                }
            }

            if (!$dryRun && $viejas !== []) {
                $this->em->flush();
            }
        }

        // ------------------------------------------------------------------
        // 2) Recálculo del contador contra los mensajes reales.
        // ------------------------------------------------------------------
        // Se cuenta de una sola consulta agrupada en vez de una por conversación:
        // con miles de conversaciones lo segundo son miles de viajes a la BD.
        $conteos = $this->em->createQueryBuilder()
            ->select('IDENTITY(m.conversation) AS conversationId', 'COUNT(m.id) AS pendientes')
            ->from(Message::class, 'm')
            ->where('m.direction = :entrante')
            ->andWhere('m.status = :recibido')
            ->setParameter('entrante', Message::DIRECTION_INCOMING)
            ->setParameter('recibido', Message::STATUS_RECEIVED)
            ->groupBy('m.conversation')
            ->getQuery()
            ->getArrayResult();

        $reales = [];
        foreach ($conteos as $fila) {
            $reales[(string) $fila['conversationId']] = (int) $fila['pendientes'];
        }

        // Se recorren TODAS las conversaciones, no solo las que tienen mensajes
        // pendientes: una con contador > 0 y ningún mensaje sin leer es
        // justamente el caso que hay que bajar a cero.
        $conversaciones = $this->em->getRepository(MessageConversation::class)->findAll();

        $corregidas = 0;
        $filas = [];

        foreach ($conversaciones as $conversacion) {
            $actual = $conversacion->getUnreadCount();
            $real = $reales[(string) $conversacion->getId()] ?? 0;

            if ($actual === $real) {
                continue;
            }

            $corregidas++;
            $filas[] = [
                $conversacion->getGuestName() ?? 'Sin nombre',
                $conversacion->getStatus(),
                $actual,
                $real,
                $real - $actual,
            ];

            if (!$dryRun) {
                $conversacion->setUnreadCount($real);
            }
        }

        if ($filas !== []) {
            $io->table(['Huésped', 'Estado', 'Antes', 'Después', 'Δ'], $filas);
        }

        if (!$dryRun && $corregidas > 0) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d conversaciones %s de %d revisadas.',
            $corregidas,
            $dryRun ? 'se corregirían' : 'corregidas',
            count($conversaciones)
        ));

        return Command::SUCCESS;
    }
}
