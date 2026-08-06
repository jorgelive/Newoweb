<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Tool\AgentActor;
use App\Agent\Tool\AgentToolInterface;
use App\Agent\Tool\AgentToolRegistry;
use App\Entity\User;
use App\Security\Roles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uso: php bin/console app:agent:permisos
 *
 * Qué herramientas ve cada perfil. Sirve para responder de un vistazo «¿puede el personal de
 * limpieza consultar reservas?» sin leer código ni gastar una llamada al modelo.
 *
 * Es la comprobación que importa al añadir una herramienta: lo que un actor no puede usar NO
 * se le menciona al modelo, así que si aparece en la fila equivocada, ese perfil puede
 * invocarla. Ver docs/Mensajeria.md §12.
 */
#[AsCommand(
    name: 'app:agent:permisos',
    description: 'Muestra qué herramientas del agente ve cada perfil.',
)]
final class AgentPermisosCommand extends Command
{
    public function __construct(
        private readonly AgentToolRegistry $registro
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $perfiles = [
            'Limpieza'      => [Roles::LIMPIEZA],
            'Mantenimiento' => [Roles::MANTENIMIENTO],
            'Recepción'     => [Roles::RESERVAS_SHOW],
            'Reservas (RW)' => [Roles::RESERVAS_SHOW, Roles::RESERVAS_WRITE],
            'Admin'         => [Roles::SUPER_ADMIN],
        ];

        $filas = [];

        foreach ($perfiles as $nombre => $roles) {
            $usuario = new User();
            $usuario->setEmail(sprintf('%s@ejemplo', strtolower((string) $nombre)));
            $usuario->setRoles($roles);

            $filas[] = [$nombre, $this->listar($this->registro->paraActor(AgentActor::delPanel($usuario)))];
        }

        // El huésped es un actor más: sus herramientas van acotadas a SU reserva.
        $filas[] = [
            'Huésped (chat)',
            $this->listar($this->registro->paraActor(
                AgentActor::huesped('whatsapp_meta', 'pms_reserva', 'ejemplo'),
                incluirEscritura: false
            )),
        ];

        $io->table(['Perfil', 'Herramientas visibles'], $filas);

        return Command::SUCCESS;
    }

    /**
     * @param list<AgentToolInterface> $herramientas
     */
    private function listar(array $herramientas): string
    {
        if ($herramientas === []) {
            return '—';
        }

        return implode(', ', array_map(
            static fn (AgentToolInterface $h) => sprintf(
                '%s (%s)',
                $h->nombre(),
                $h->nivelRiesgo()->value
            ),
            $herramientas
        ));
    }
}
