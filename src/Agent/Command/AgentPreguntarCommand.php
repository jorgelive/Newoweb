<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Service\PanelAssistant;
use App\Agent\Access\AgentActor;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Security\Roles;
use RuntimeException;
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
        private readonly PanelAssistant $asistente,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pregunta', InputArgument::REQUIRED, 'La pregunta en lenguaje natural')
            ->addOption(
                'como',
                null,
                InputOption::VALUE_REQUIRED,
                'Email del usuario cuyos permisos usar. Sin él, se pregunta como SUPER_ADMIN.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->asistente->estaDisponible()) {
            $io->error('Falta ANTHROPIC_API_KEY: el asistente está desactivado en este entorno.');
            return Command::FAILURE;
        }

        try {
            $actor = $this->resolverActor($input->getOption('como'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        $io->comment('Preguntando como: ' . $actor->etiqueta());

        $inicio = microtime(true);

        try {
            $resultado = $this->asistente->preguntar((string) $input->getArgument('pregunta'), $actor);
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

    /**
     * Con `--como` se pregunta con los permisos reales de ese usuario, que es la forma de
     * comprobar que limpieza y administración NO ven las mismas herramientas. Sin la opción
     * se usa un SUPER_ADMIN sintético, para probar el prompt sin depender de la base de datos.
     */
    private function resolverActor(mixed $email): AgentActor
    {
        if ($email === null) {
            $admin = new User();
            $admin->setEmail('cli@local');
            $admin->setRoles([Roles::SUPER_ADMIN]);

            return AgentActor::delPanel($admin);
        }

        $usuario = $this->em->getRepository(User::class)->findOneBy(['email' => (string) $email]);
        if (!$usuario instanceof User) {
            throw new RuntimeException(sprintf('No existe ningún usuario con el email "%s".', $email));
        }

        return AgentActor::delPanel($usuario);
    }
}
