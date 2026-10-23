<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Service\DecisionDelSondeo;
use App\Agent\Service\SondeoDeMotores;
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
 * Sondea TODOS los motores con credenciales —los de los tramos y los que no están en ninguno, como
 * DeepSeek—, cada uno con su estado y su aviso. Hasta el 22/10/2026 sólo el motor por defecto: el
 * tramo bajo iba a Gemini, se le agotaron los créditos y el sondeo seguía diciendo «anthropic ·
 * responde». Un motor fuera de tramo también importa: es el último suplente
 * (`SelectorDePotencia::suplenteDe()`) y el panel lo puede elegir en el desplegable. Una generación mínima por motor: del orden de
 * céntimos al mes. Cada motor se sondea con SU modelo por defecto, no con el del tramo: lo que se
 * busca es la cuenta (créditos, clave), que es la misma.
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
        private readonly SondeoDeMotores $sondeo,
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
        if ($input->getOption('sin-avisar') === true) {
            $fallos = $this->sondeo->comprobar();

            if ($fallos === []) {
                $io->warning('No hay ningún motor de IA configurado.');
            }

            foreach ($fallos as $nombre => $fallo) {
                $fallo === null
                    ? $io->success(sprintf('«%s» responde.', $nombre))
                    : $io->error(sprintf('«%s» NO responde: %s', $nombre, $fallo));
            }

            return Command::SUCCESS;
        }

        $resultado = $this->sondeo->sondear();

        if ($resultado === []) {
            // Sin ningún motor configurado no hay nada que sondear: es una decisión de configuración,
            // no una caída, y el agente ya lo dice en cada turno.
            $io->warning('No hay ningún motor de IA configurado.');
        }

        foreach ($resultado as $nombre => ['fallo' => $fallo, 'accion' => $accion]) {
            $io->writeln(sprintf(
                '%s · %s · %s',
                $nombre,
                $fallo === null ? 'responde' : 'NO responde: ' . $fallo,
                match ($accion) {
                    DecisionDelSondeo::AVISAR_CAIDA => 'avisado de la caída',
                    DecisionDelSondeo::AVISAR_RECUPERACION => 'avisado de la recuperación',
                    default => 'sin aviso',
                },
            ));
        }

        return Command::SUCCESS;
    }
}
