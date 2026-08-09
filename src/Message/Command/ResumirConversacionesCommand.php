<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Resumen\ResumenConversacionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Genera el resumen IA de las conversaciones que tienen mensajes sin leer.
 *
 * El resumen se regenera solo cuando ENTRA un mensaje. Las conversaciones que ya
 * estaban pendientes cuando se desplegó esto se quedarían sin resumen hasta que el
 * huésped volviera a escribir, que puede ser nunca. Este comando las rellena.
 *
 * También sirve para reprocesar a mano tras cambiar el prompt: `--forzar` ignora la
 * marca de idempotencia y vuelve a preguntarle al modelo.
 *
 * ⚠️ Cada conversación sin resumen es UNA llamada al modelo. Con `--dry-run` se ve
 * cuántas serían antes de gastar nada.
 */
#[AsCommand(
    name: 'app:message:resumir',
    description: 'Genera el resumen IA de las conversaciones con mensajes sin leer.'
)]
final class ResumirConversacionesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResumenConversacionService $resumen
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enumera las conversaciones que se resumirían, sin llamar al modelo.')
            ->addOption('forzar', null, InputOption::VALUE_NONE, 'Regenera aunque ya tengan resumen (tras cambiar el prompt).')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Máximo de conversaciones a procesar.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $forzar = (bool) $input->getOption('forzar');
        $limite = max(1, (int) $input->getOption('limite'));

        $qb = $this->em->getRepository(MessageConversation::class)->createQueryBuilder('c')
            ->where('c.unreadCount > 0')
            ->orderBy('c.lastMessageAt', 'DESC')
            ->setMaxResults($limite);

        if (!$forzar) {
            $qb->andWhere('c.resumenIa IS NULL');
        }

        $conversaciones = $qb->getQuery()->getResult();

        if ($conversaciones === []) {
            $io->success('No hay conversaciones pendientes de resumir.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('%d conversaciones %s', count($conversaciones), $dryRun ? 'se resumirían' : 'a resumir'));

        foreach ($conversaciones as $conversacion) {
            $nombre = $conversacion->getGuestName() ?? 'Sin nombre';

            if ($dryRun) {
                $io->writeln(sprintf('  · %s — %d sin leer', $nombre, $conversacion->getUnreadCount()));
                continue;
            }

            if ($forzar) {
                // Borrar la marca es lo que hace que el servicio vuelva a preguntar:
                // su guarda de idempotencia lo pararía en seco.
                $conversacion->setResumenIaHasta(null);
            }

            $motivo = $this->resumen->actualizar($conversacion);
            $resultado = $conversacion->getResumenIa();

            // Cuando no hay resumen se enseña el MOTIVO, no un «revisa el log»: en prod
            // monolog usa fingers_crossed con action_level error, así que los warning de
            // un comando que termina bien no llegan a escribirse nunca.
            $io->writeln(sprintf(
                '  · %-32s %s',
                mb_strimwidth($nombre, 0, 32),
                $resultado ?? "⚠️  {$motivo}"
            ));
        }

        if ($dryRun) {
            $io->warning('DRY-RUN: no se llamó al modelo.');
        } else {
            $io->success('Listo.');
        }

        return Command::SUCCESS;
    }
}
