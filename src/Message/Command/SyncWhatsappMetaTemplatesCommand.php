<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Service\Meta\Template\WhatsappMetaTemplateSyncService; // Ajusta el namespace si lo moviste
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Comando para ejecutar la sincronización de plantillas de WhatsApp Meta desde la CLI.
 * * ¿Por qué existe? Permite automatizar la descarga de plantillas aprobadas mediante un Cronjob
 * nocturno, asegurando que el PMS siempre tenga los IDs oficiales sin intervención manual.
 */
#[AsCommand(
    name: 'app:whatsapp:sync-templates',
    description: 'Sincroniza las plantillas locales del PMS con la API de WhatsApp Meta.'
)]
class SyncWhatsappMetaTemplatesCommand extends Command
{
    public function __construct(
        private readonly WhatsappMetaTemplateSyncService $syncService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Iniciando sincronización con WhatsApp Meta API');

        try {
            $io->text('Consultando plantillas y procesando paginación desde Meta...');

            // El servicio ahora encapsula TODA la lógica de base de datos y conexión HTTP
            $results = $this->syncService->sync();

            $created = $results['created'] ?? 0;
            $updated = $results['updated'] ?? 0;

            // Resultado final
            if ($created > 0 || $updated > 0) {
                $io->success(sprintf(
                    'Sincronización exitosa. Se crearon %d plantilla(s) nueva(s) y se actualizaron %d existente(s).',
                    $created,
                    $updated
                ));
            } else {
                $io->info('Sincronización finalizada. No se detectaron plantillas nuevas o pendientes de actualizar.');
            }

            return Command::SUCCESS;

        } catch (Throwable $exception) {
            $io->error('Ocurrió un error crítico durante la sincronización:');
            $io->writeln($exception->getMessage());

            return Command::FAILURE;
        }
    }
}