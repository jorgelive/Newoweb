<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Access\AgentActor;
use App\Agent\Access\AgentActorFactory;
use App\Agent\Skill\SkillRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Ejecuta UNA skill directamente, sin modelo y sin gastar un céntimo.
 *
 * ```
 * php bin/console app:agent:skill listar_entradas_salidas '{"tipo":"salidas","dias":5}'
 * php bin/console app:agent:skill buscar_reserva '{"busqueda":"Acuña"}'
 * php bin/console app:agent:skill consultar_mi_reserva '{}' --contexto=<uuid-reserva>
 * ```
 *
 * Separa dos preguntas que conviene no mezclar cuando algo va mal: **¿falla la skill o falla
 * el modelo?** Si aquí devuelve lo que debe, el problema está en el prompt o en la
 * descripción de la skill, no en los datos.
 *
 * Es además la forma de ver lo que el modelo ve de verdad: la salida es literalmente el JSON
 * que recibe como resultado de la herramienta.
 */
#[AsCommand(
    name: 'app:agent:skill',
    description: 'Ejecuta una skill del agente directamente, sin pasar por el modelo.',
)]
final class AgentSkillCommand extends Command
{
    public function __construct(
        private readonly SkillRegistry $registro,
        private readonly UserRepository $usuarios,
        private readonly AgentActorFactory $actores,
        private readonly GuardiaDeSkills $guardia,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('skill', InputArgument::REQUIRED, 'Nombre de la skill')
            ->addArgument('entrada', InputArgument::OPTIONAL, 'Parámetros en JSON', '{}')
            ->addOption('contexto', null, InputOption::VALUE_REQUIRED, 'UUID de reserva, para las skills acotadas al contexto')
            ->addOption('como-huesped', null, InputOption::VALUE_NONE, 'Ejecutar con ROLE_HUESPED en vez de SUPER_ADMIN')
            ->addOption('como-prospecto', null, InputOption::VALUE_NONE, 'Ejecutar con ROLE_PROSPECTO: quien pregunta sin reserva ninguna')
            ->addOption('conversacion', null, InputOption::VALUE_REQUIRED, 'UUID del HILO en curso. No es el contexto: no autoriza a leer nada, solo dice en qué conversación se está. Es lo que necesita escalar_al_equipo')
            ->addOption('usuario', null, InputOption::VALUE_REQUIRED, 'Username de un usuario REAL: ejecuta con su identidad y sus roles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $nombre = (string) $input->getArgument('skill');
        $skill = $this->registro->buscar($nombre);

        if ($skill === null) {
            $io->error(sprintf('No existe la skill "%s".', $nombre));
            $io->listing(array_map(static fn ($s) => $s->nombre(), $this->registro->todas()));

            return Command::INVALID;
        }

        $entrada = json_decode((string) $input->getArgument('entrada'), true);
        if (!is_array($entrada)) {
            $io->error('Los parámetros deben ser un objeto JSON válido.');
            return Command::INVALID;
        }

        $contexto = $input->getOption('contexto');
        $username = $input->getOption('usuario');

        $conversacion = $input->getOption('conversacion');
        $conversacion = $conversacion !== null ? trim((string) $conversacion) : null;
        $conversacion = $conversacion === '' ? null : $conversacion;

        if ($username !== null) {
            $usuario = $this->usuarios->findOneBy(['username' => (string) $username]);

            if ($usuario === null) {
                $io->error(sprintf('No existe el usuario "%s".', $username));
                return Command::INVALID;
            }

            // Por la factoría: con los roles literales, un usuario real con ROLE_*_DELETE no
            // pasaría un control que pida ROLE_*_WRITE y la prueba mentiría.
            $actor = $contexto !== null
                ? $this->actores->delEquipoPorChat($usuario, 'cli', 'pms_reserva', (string) $contexto)
                : $this->actores->delPanel($usuario);
        } elseif ($input->getOption('como-prospecto')) {
            // Sin contexto ni aunque se pase `--contexto`: un prospecto sin contexto es la
            // definición, no una limitación de la prueba. El HILO sí se le pasa: es lo único
            // que le permite escalar, y sin él esta prueba no podía reproducir el camino real
            // —que es justo donde estaba el bug—.
            // Por la factoría: es la que puebla `dominios()`, y probar una skill con un actor
            // que no los lleva mide un catálogo que en producción no existe.
            $actor = $this->actores->prospecto('cli', $conversacion);
        } elseif ($input->getOption('como-huesped')) {
            $actor = $this->actores->huesped('cli', 'pms_reserva', $contexto !== null ? (string) $contexto : null, $conversacion);
        } else {
            $actor = $this->actorAdmin($contexto !== null ? (string) $contexto : null);
        }

        // Por el guardián y no comprobando los roles aquí: era la misma política escrita dos
        // veces, y la copia de la CLI no sabía nada del PIN. Ver GuardiaDeSkills.
        $bloqueo = $this->guardia->motivoDeBloqueo($skill, $actor);

        if ($bloqueo !== null) {
            $io->error($bloqueo);

            return Command::FAILURE;
        }

        $inicio = microtime(true);

        try {
            $resultado = $skill->ejecutar($entrada, $actor);
        } catch (Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Sin formatear: es exactamente lo que llega al modelo.
        $io->writeln(json_encode(
            json_decode($resultado->aJson(), true),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $io->comment(sprintf(
            '%s · %.0f ms · %d caracteres',
            $resultado->esError() ? 'ERROR de negocio' : 'ok',
            (microtime(true) - $inicio) * 1000,
            strlen($resultado->aJson())
        ));

        return Command::SUCCESS;
    }

    private function actorAdmin(?string $contexto): AgentActor
    {
        $admin = new User();
        $admin->setEmail('cli@local');
        $admin->setRoles([Roles::SUPER_ADMIN]);

        return $contexto !== null
            ? AgentActor::delEquipoPorChat($admin, 'cli', 'pms_reserva', $contexto)
            : AgentActor::delPanel($admin);
    }
}
