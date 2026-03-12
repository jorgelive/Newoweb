<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Reserva\PmsReservaRecalculoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Comando para reconstruir masivamente el contexto de las conversaciones.
 * Útil tras cambios estructurales en el JSON agnóstico o reglas de archivado.
 */
#[AsCommand(
    name: 'app:message:rebuild-context',
    description: 'Sincroniza el JSON de hitos, unidades y estado de todas las conversaciones de reservas.'
)]
class RebuildConversationContextCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsReservaRecalculoService $recalculoService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Reconstrucción de Contexto de Mensajería');

        // 1. Buscamos todas las reservas que tienen una conversación asociada
        $reservaIds = $this->entityManager->getRepository(MessageConversation::class)
            ->createQueryBuilder('c')
            ->select('DISTINCT c.contextId')
            ->where('c.contextType = :type')
            ->setParameter('type', 'pms_reserva')
            ->getQuery()
            ->getSingleColumnResult();

        $total = count($reservaIds);

        if ($total === 0) {
            $io->warning('No se encontraron conversaciones de reservas para actualizar.');
            return Command::SUCCESS;
        }

        $io->note(sprintf('Se han detectado %d reservas vinculadas a chats. Iniciando recálculo y sincronización...', $total));

        // 2. Ejecutamos el servicio de recálculo por lotes
        // Como ahora tu PmsReservaRecalculoService ya incluye la actualización del Chat,
        // simplemente llamar al servicio actualizará todo automáticamente.

        $batchSize = 100;
        $io->progressStart($total);

        foreach (array_chunk($reservaIds, $batchSize) as $chunk) {
            // Este método ahora:
            // - Actualiza SQL (unidadesAggregate, montos, etc.)
            // - Actualiza el Chat (JSON milestones, items, etc.)
            // - Archiva si la reserva está cancelada
            $this->recalculoService->recalcularDesdeEventos(
                reservaIds: $chunk,
                entityManager: $this->entityManager,
                flush: true
            );

            $io->progressAdvance(count($chunk));

            // Limpiamos el Entity Manager para evitar desbordamiento de memoria
            $this->entityManager->clear();
        }

        $io->progressFinish();

        $io->success([
            'Sincronización completada.',
            'Se han recalculado los agregados de las reservas y actualizado el JSON/Estado de los chats.'
        ]);

        return Command::SUCCESS;
    }
}