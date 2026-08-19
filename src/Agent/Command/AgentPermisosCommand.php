<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Access\AgentActor;
use App\Agent\Access\AgentActorFactory;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillRegistry;
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
    description: 'Muestra qué skills del agente ve cada perfil.',
)]
final class AgentPermisosCommand extends Command
{
    public function __construct(
        private readonly SkillRegistry $registro,
        // ⚠️ Por la FACTORÍA y no por `AgentActor::` a secas, y no es un detalle: la factoría es
        // la que puebla `dominios()`, y sin ellos el catálogo que sale por aquí NO es el que ve
        // un actor real. Esta tabla se usa para auditar permisos; si miente, miente en el sitio
        // donde más caro sale creerla.
        private readonly AgentActorFactory $actores,
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
            // Operación es un perfil de verdad, no un hueco entre Recepción y Admin: es el que
            // mantiene el catálogo de servicios y tarifas. Sin estas dos filas, sus skills sólo
            // se veían bajo Admin y parecían no existir.
            'Operaciones'      => [Roles::OPERACIONES_SHOW],
            'Operaciones (RW)' => [Roles::OPERACIONES_SHOW, Roles::OPERACIONES_WRITE],
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
                $this->actores->huesped('whatsapp_meta', 'pms_reserva', 'ejemplo'),
                incluirEscritura: false
            )),
        ];

        // Y el prospecto: quien pregunta sin ser todavía nadie. Nace SIN contexto a propósito
        // —ver AgentActor::prospecto()—, así que esta fila enseña justo lo que puede un número
        // desconocido que escribe a preguntar precios.
        $filas[] = [
            'Prospecto (chat)',
            $this->listar($this->registro->paraActor(
                $this->actores->prospecto('whatsapp_meta'),
                incluirEscritura: false
            )),
        ];

        $io->table(['Perfil', 'Skills visibles'], $filas);

        return Command::SUCCESS;
    }

    /**
     * @param list<SkillInterface> $skills
     */
    private function listar(array $skills): string
    {
        if ($skills === []) {
            return '—';
        }

        return implode(', ', array_map(
            static fn (SkillInterface $h) => sprintf(
                '%s (%s)',
                $h->nombre(),
                $h->nivelRiesgo()->value
            ),
            $skills
        ));
    }
}
