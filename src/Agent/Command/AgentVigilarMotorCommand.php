<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Conversation\AgentEngineRegistry;
use App\Agent\Conversation\MotorSondeableInterface;
use App\Agent\Service\DecisionDelSondeo;
use App\Agent\Service\VigilanteDelMotor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pregunta al motor de IA «¿respondes?» cada 30 minutos, sin esperar a que un huésped choque con el
 * fallo (17/10/2026). Avisa al equipo UNA vez por caída y otra al recuperarse: ver
 * `DecisionDelSondeo` y `VigilanteDelMotor::resultadoDelSondeo()`.
 *
 * Sondea el motor POR DEFECTO, que es el que contesta a los huéspedes. Una generación mínima: del
 * orden de céntimos al mes.
 *
 *   php bin/console app:agent:vigilar-motor               # el sondeo de la tarea programada
 *   php bin/console app:agent:vigilar-motor --sin-avisar  # sólo mirar, sin tocar el estado
 */
#[AsCommand(
    name: 'app:agent:vigilar-motor',
    description: 'Comprueba que el motor de IA responde y avisa al equipo si se cae (una vez por caída).',
)]
final class AgentVigilarMotorCommand extends Command
{
    public function __construct(
        private readonly AgentEngineRegistry $motores,
        private readonly VigilanteDelMotor $vigilante,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sin-avisar', null, InputOption::VALUE_NONE, 'Sólo comprobar: ni avisa ni cambia el estado guardado.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $motor = $this->motores->porDefecto();

        if ($motor === null) {
            // Sin ningún motor configurado no hay nada que sondear: es una decisión de configuración,
            // no una caída, y el agente ya lo dice en cada turno.
            $io->warning('No hay ningún motor de IA configurado.');

            return Command::SUCCESS;
        }

        if (!$motor instanceof MotorSondeableInterface) {
            $io->warning(sprintf('El motor «%s» no sabe sondearse.', $motor->nombre()));

            return Command::SUCCESS;
        }

        $fallo = $motor->sondear();

        if ($input->getOption('sin-avisar') === true) {
            $fallo === null
                ? $io->success(sprintf('«%s» responde.', $motor->nombre()))
                : $io->error(sprintf('«%s» NO responde: %s', $motor->nombre(), $fallo));

            return Command::SUCCESS;
        }

        $accion = $this->vigilante->resultadoDelSondeo($motor->nombre(), $fallo);

        $io->writeln(sprintf(
            '%s · %s · %s',
            $motor->nombre(),
            $fallo === null ? 'responde' : 'NO responde: ' . $fallo,
            match ($accion) {
                DecisionDelSondeo::AVISAR_CAIDA => 'avisado de la caída',
                DecisionDelSondeo::AVISAR_RECUPERACION => 'avisado de la recuperación',
                default => 'sin aviso',
            },
        ));

        return Command::SUCCESS;
    }
}
