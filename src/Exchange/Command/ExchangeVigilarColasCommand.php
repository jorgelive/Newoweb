<?php

declare(strict_types=1);

namespace App\Exchange\Command;

use App\Exchange\Service\VigilanteDeColas;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Avisa de webhooks y colas que se quedaron atascados.
 *
 * Pensado para cron cada hora. Ver el porqué en {@see VigilanteDeColas}: el sistema ya escribía
 * los estados que delataban el fallo del 15/08/2026 —tres cancelaciones perdidas— y nadie los
 * miraba.
 */
#[AsCommand(
    name: 'app:exchange:vigilar-colas',
    description: 'Avisa si hay webhooks sin procesar o ítems de cola fallidos.'
)]
final class ExchangeVigilarColasCommand extends Command
{
    public function __construct(
        private readonly VigilanteDeColas $vigilante,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'horas',
                null,
                InputOption::VALUE_REQUIRED,
                'Ventana a revisar. Corta a propósito: un fallo viejo que ya se decidió dejar estar no debe sonar cada hora.',
                '24'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice qué avisaría, sin avisar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $horas = (int) $input->getOption('horas');
        $seco = (bool) $input->getOption('dry-run');

        $lineas = $this->vigilante->revisar($horas);

        if ([] === $lineas) {
            $io->success(sprintf('Nada atascado en las últimas %d horas.', $horas));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Hay trabajo atascado en las últimas %d horas:', $horas));
        $io->listing($lineas);

        if ($seco) {
            $io->note('Modo seco: no se avisó a nadie.');

            return Command::SUCCESS;
        }

        $this->vigilante->avisar($lineas);
        $io->text('Aviso enviado a quien tenga OPERACIONES_SHOW.');

        return Command::SUCCESS;
    }
}
