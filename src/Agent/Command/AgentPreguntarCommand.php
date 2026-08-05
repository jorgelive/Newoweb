<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Service\PanelAssistant;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Uso:
 *   php bin/console app:agent:preguntar "¿qué casitas tengo libres del 12 al 15 de marzo?"
 *
 * Prueba el asistente del panel sin pasar por el navegador. Es la forma de validar el
 * prompt y el uso de herramientas —y de ver cuánto tarda y cuánto cuesta— antes de
 * enseñárselo a nadie.
 */
#[AsCommand(
    name: 'app:agent:preguntar',
    description: 'Pregunta al asistente interno del panel desde la terminal.',
)]
final class AgentPreguntarCommand extends Command
{
    public function __construct(
        private readonly PanelAssistant $asistente
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('pregunta', InputArgument::REQUIRED, 'La pregunta en lenguaje natural');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->asistente->estaDisponible()) {
            $io->error('Falta ANTHROPIC_API_KEY: el asistente está desactivado en este entorno.');
            return Command::FAILURE;
        }

        $inicio = microtime(true);

        try {
            $resultado = $this->asistente->preguntar((string) $input->getArgument('pregunta'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln($resultado['respuesta']);
        $io->newline();
        $io->comment(sprintf(
            '%.1f s · herramientas: %s',
            microtime(true) - $inicio,
            $resultado['herramientas'] === [] ? 'ninguna' : implode(', ', $resultado['herramientas'])
        ));

        return Command::SUCCESS;
    }
}
