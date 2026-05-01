<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AutoTranslationService;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItemDiccionario;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:travel:auto-traducir',
    description: 'Traduce masivamente todo el catálogo logístico inyectando el servicio directamente y guardando correctamente.',
)]
class TravelAutoTraducirCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AutoTranslationService $translationService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Iniciando Traducción Masiva del Catálogo Travel');

        // ==========================================
        // 1. TRADUCIR COMPONENTES
        // ==========================================
        $componentes = $this->entityManager->getRepository(TravelComponente::class)->findAll();
        $io->section(sprintf('Traduciendo %d Componentes Logísticos...', count($componentes)));

        $progressBar = $io->createProgressBar(count($componentes));
        $progressBar->start();

        foreach ($componentes as $i => $componente) {
            // Inyectamos true para forzar ejecución y false para no sobrescribir lo ya traducido
            $this->translationService->processEntity($componente, true, false);

            // Flush cada 20 registros, asegurando que $i sea mayor que 0
            if ($i > 0 && ($i % 20) === 0) {
                $this->entityManager->flush();
            }
            $progressBar->advance();
        }
        $this->entityManager->flush(); // Guardamos el remanente
        $progressBar->finish();
        $io->newLine(2);

        // ==========================================
        // 2. TRADUCIR DICCIONARIO DE ÍTEMS
        // ==========================================
        $diccionario = $this->entityManager->getRepository(TravelItemDiccionario::class)->findAll();
        $io->section(sprintf('Traduciendo %d Ítems del Diccionario...', count($diccionario)));

        $progressBarDic = $io->createProgressBar(count($diccionario));
        $progressBarDic->start();

        foreach ($diccionario as $i => $item) {
            $this->translationService->processEntity($item, true, false);

            if ($i > 0 && ($i % 20) === 0) {
                $this->entityManager->flush();
            }
            $progressBarDic->advance();
        }
        $this->entityManager->flush(); // Guardamos el remanente
        $progressBarDic->finish();
        $io->newLine(2);

        // ==========================================
        // 3. TRADUCIR TARIFAS
        // ==========================================
        $tarifas = $this->entityManager->getRepository(TravelTarifa::class)->findAll();
        $io->section(sprintf('Traduciendo %d Tarifas...', count($tarifas)));

        $progressBarTarifas = $io->createProgressBar(count($tarifas));
        $progressBarTarifas->start();

        foreach ($tarifas as $i => $tarifa) {
            $this->translationService->processEntity($tarifa, true, false);

            if ($i > 0 && ($i % 20) === 0) {
                $this->entityManager->flush();
            }
            $progressBarTarifas->advance();
        }
        $this->entityManager->flush(); // Guardamos el remanente
        $progressBarTarifas->finish();
        $io->newLine(2);

        $io->success('¡Traducción Directa por Servicio completada y guardada exitosamente!');

        return Command::SUCCESS;
    }
}