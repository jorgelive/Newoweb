<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageConversation;
use App\Message\Service\Queue\MessageRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uso:
 * php bin/console app:message:sync-rules 019cea14-bdd4-769e-bd63-8abac315738c
 * php bin/console app:message:sync-rules --all
 * php bin/console app:message:sync-rules --closed
 * php bin/console app:message:sync-rules <uuid> --force
 *
 * Este comando es además el ÚNICO barrido periódico del módulo: nada más re-evalúa las
 * reglas por el paso del tiempo. Ver docs/Mensajeria.md §6 para la entrada de cron.
 */
#[AsCommand(
    name: 'app:message:sync-rules',
    description: 'Evalúa y programa mensajes automáticos basados en reglas para las conversaciones.',
)]
class MessageSyncRulesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageRuleEngine $ruleEngine
    ) {
        parent::__construct();
    }

    /**
     * Cuántas filas hay en lo que este comando puede tocar.
     *
     * Es la única forma de contar el efecto de un barrido sin fiarse de lo que diga el motor por
     * pantalla: se mira lo que hay en la base antes y después.
     *
     * @return array<string, int>
     */
    private function fotoDeLasColas(): array
    {
        $conexion = $this->em->getConnection();
        $foto = [];

        foreach (['msg_message', 'msg_whatsapp_meta_send_queue'] as $tabla) {
            $foto[$tabla] = (int) $conexion->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $tabla));
        }

        return $foto;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('conversation_id', InputArgument::OPTIONAL, 'UUID de una conversación específica a sincronizar')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Sincroniza todas las conversaciones con estado OPEN')
            ->addOption('closed', null, InputOption::VALUE_NONE, 'Barredora: Sincroniza conversaciones con estado CLOSED o ARCHIVED para cancelar colas pendientes')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Reparación: programa hitos CREATED, rescata vencidos dentro de las últimas 24h y regenera mensajes fallidos sin reintentos')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ejecuta de verdad pero DESHACE al final: enseña qué mensajes saldrían sin encolar ninguno');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conversationId = $input->getArgument('conversation_id');
        $syncAll = $input->getOption('all');
        $syncClosed = $input->getOption('closed');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$conversationId && !$syncAll && !$syncClosed) {
            $io->error('Debes proporcionar un UUID de conversación, usar la opción --all, o usar la opción --closed');
            return Command::FAILURE;
        }

        // ── Cómo se simula, y por qué así ───────────────────────────────────
        // No hay un «modo simulación» dentro del motor, y ponerlo habría significado un `if` en
        // cada escritura: la simulación dejaría de ejercitar el camino real justo donde importa.
        // En su lugar se corre TODO de verdad dentro de una transacción y se deshace al final.
        //
        // Sirve porque lo que este comando produce son FILAS —mensajes y sus colas—, no envíos:
        // quien manda de verdad es un worker que lee esas filas después. Si nunca se
        // confirman, nadie las lee.
        //
        // ⚠️ Lo que NO cubre: si algún día una regla llamara a un servicio externo en caliente
        // —un HTTP, un correo directo—, eso escapa de la transacción. Hoy no pasa; si pasa,
        // esta opción deja de ser segura y hay que decirlo aquí.
        $antes = [];

        if ($dryRun) {
            $antes = $this->fotoDeLasColas();
            $this->em->getConnection()->beginTransaction();
            $io->warning('DRY RUN: se ejecuta todo de verdad y se deshace al final. No se encola nada.');
        }

        $repository = $this->em->getRepository(MessageConversation::class);
        $conversationIds = [];

        // 🔥 CORRECCIÓN: Solo extraemos los IDs (strings) de la base de datos, no los objetos completos
        if ($conversationId) {
            $conversationIds[] = $conversationId;
        } elseif ($syncAll || $syncClosed) {
            $qb = $repository->createQueryBuilder('c')->select('c.id');

            if ($syncAll) {
                $qb->where('c.status = :status')
                    ->setParameter('status', MessageConversation::STATUS_OPEN);
            } else {
                $qb->where('c.status IN (:statuses)')
                    ->setParameter('statuses', [MessageConversation::STATUS_CLOSED, MessageConversation::STATUS_ARCHIVED]);
            }

            $results = $qb->getQuery()->getArrayResult();
            $conversationIds = array_column($results, 'id');

            $tipo = $syncAll ? 'ABIERTAS (OPEN)' : 'CERRADAS/ARCHIVADAS';
            $io->info(sprintf('Se encontraron %d conversaciones %s para evaluar.', count($conversationIds), $tipo));
        }

        $io->progressStart(count($conversationIds));

        $countSynced = 0;
        foreach ($conversationIds as $id) {
            try {
                // 🔥 Cargamos la entidad "fresca" en cada iteración
                $conversation = $repository->find($id);

                if ($conversation) {
                    // TRIGGER_COMMAND, no el UPDATE por defecto: el motor distingue el barrido
                    // manual del reactivo para decidir blindajes y rescates.
                    $this->ruleEngine->syncConversationRules(
                        $conversation,
                        MessageRuleEngine::TRIGGER_COMMAND,
                        $force
                    );
                    $countSynced++;
                }
            } catch (\Throwable $e) {
                $io->warning(sprintf('Error al procesar la conversación %s: %s', $id, $e->getMessage()));
            }

            $io->progressAdvance();

            // Liberar memoria de forma segura sin romper los objetos pendientes
            if (($syncAll || $syncClosed) && $countSynced % 50 === 0) {
                $this->em->clear();
            }
        }

        $io->progressFinish();

        if ($dryRun) {
            $this->em->flush();
            $despues = $this->fotoDeLasColas();

            $filas = [];

            foreach ($despues as $tabla => $cuantas) {
                $filas[] = [$tabla, $antes[$tabla] ?? 0, $cuantas, $cuantas - ($antes[$tabla] ?? 0)];
            }

            $this->em->getConnection()->rollBack();

            $io->table(['Tabla', 'Antes', 'Después', 'Diferencia'], $filas);
            $io->success(sprintf(
                '%d conversaciones evaluadas. Todo deshecho: la base queda como estaba.',
                $countSynced
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Sincronización completada. Se evaluaron %d conversaciones.', $countSynced));

        return Command::SUCCESS;
    }
}