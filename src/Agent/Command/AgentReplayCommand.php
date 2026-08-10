<?php

declare(strict_types=1);

namespace App\Agent\Command;

use App\Agent\Access\AgentActorFactory;
use App\Agent\Conversation\ConversationRequest;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Conversation\SelectorDePotencia;
use App\Agent\Service\AiConversationProcessor;
use App\Agent\Triage\TipoDeMensaje;
use App\Agent\Triage\Triaje;
use App\Entity\User;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vuelve a pasarle al agente ACTUAL una conversación que ya ocurrió, para ver qué contestaría hoy.
 *
 * Nació de la reserva V4JE5Q (docs/Mensajeria.md §16.1), donde el agente se inventó un número de
 * Yape, un tipo de cambio y una URL de pago. Arreglado el hueco de datos, la pregunta que queda
 * no se responde mirando el JSON de una skill: **lo que lee el huésped es lo que redacta el
 * modelo**, y sólo se ve haciéndole las mismas preguntas otra vez.
 *
 * ### Qué reproduce, y qué no
 *
 * El mismo camino que {@see AiConversationProcessor::generar()}: triaje primero y, si no era
 * charla, el catálogo entero con el actor del huésped y `permitirEscritura: false`. Los prompts
 * salen por reflexión de `reglas()` y `contexto()` — copiarlos aquí sería una copia que envejece
 * sola y acabaría probando algo que ya no está en producción.
 *
 * **No persiste nada**: el historial se acumula en memoria, así que no crea mensajes, no encola
 * envíos y no marca la conversación como no leída. Se puede correr veinte veces seguidas.
 *
 * ### ⚠️ Lo único que sí tiene efectos: las skills
 *
 * Si el modelo llama a una skill, se ejecuta de verdad contra la base de datos a la que apuntes.
 * Con el actor de huésped todas son de lectura menos una: `escalar_al_equipo` **manda WhatsApp**
 * a quien tenga `ROLE_CUSTOMER_SUPPORT` y móvil. Por eso el comando se planta si encuentra
 * guardia con teléfono y obliga a pasar `--con-guardia` para seguir. Vaciar esos teléfonos
 * mientras se prueba es lo razonable — y de paso se ve que la skill se invocó, que es el dato
 * que importa, sin despertar a nadie.
 *
 * Uso:
 *   php bin/console app:agent:replay <uuid-reserva> --guion=var/guion.json
 *   php bin/console app:agent:replay <uuid-reserva>     # los mensajes de esa conversación
 */
#[AsCommand(
    name: 'app:agent:replay',
    description: 'Reproduce una conversación de huésped contra el agente actual, sin guardar nada.',
)]
final class AgentReplayCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiConversationProcessor $procesador,
        private readonly Triaje $triaje,
        private readonly SelectorDePotencia $potencias,
        private readonly AgentActorFactory $actores,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('reserva', InputArgument::REQUIRED, 'UUID de la reserva cuyo chat se reproduce')
            ->addOption('guion', null, InputOption::VALUE_REQUIRED, 'JSON con un array de mensajes del huésped. Sin él, se usan los mensajes entrantes de la conversación.')
            ->addOption('con-guardia', null, InputOption::VALUE_NONE, 'Seguir aunque haya gente de guardia con móvil: una escalada les llegará de verdad.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reservaId = (string) $input->getArgument('reserva');

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);

        if ($reserva === null) {
            $io->error(sprintf('No existe la reserva %s.', $reservaId));
            return Command::INVALID;
        }

        $conversacion = $this->em->getRepository(MessageConversation::class)
            ->findOneBy(['contextType' => 'pms_reserva', 'contextId' => $reservaId]);

        if ($conversacion === null) {
            $io->error('Esa reserva no tiene conversación, y el agente del huésped se acota por ella.');
            return Command::INVALID;
        }

        if (!$this->guardiaDespejada($io, $input->getOption('con-guardia'))) {
            return Command::FAILURE;
        }

        $guion = $this->guion($input->getOption('guion'), $conversacion);

        if ($guion === []) {
            $io->error('No hay mensajes que reproducir.');
            return Command::INVALID;
        }

        // Los prompts REALES. Son privados a propósito y aquí se leen por reflexión: es una
        // herramienta de diagnóstico, no un consumidor con derecho a esa API.
        $reflex = new ReflectionClass(AiConversationProcessor::class);
        $reglas = $reflex->getMethod('reglas');
        $contextoDe = $reflex->getMethod('contexto');

        $actor = $this->actores->huesped('beds24', 'pms_reserva', $reservaId);
        $historial = [];

        $io->title(sprintf(
            '%s · %s',
            (string) $reserva->getLocalizador(),
            trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente())
        ));

        foreach ($guion as $i => $mensaje) {
            $io->writeln(sprintf("\n<fg=cyan>[%d] 👤 %s</>", $i + 1, str_replace("\n", "\n         ", $mensaje)));

            $decision = $this->triaje->clasificar(
                $actor,
                $mensaje,
                $historial,
                (string) $contextoDe->invoke($this->procesador, $conversacion)
            );

            $io->writeln(sprintf(
                '     <comment>triaje: %s%s</comment>',
                $decision->tipo->value,
                $decision->skill !== null ? " → {$decision->skill}" : ''
            ));

            if ($decision->tipo === TipoDeMensaje::Conversacion && $decision->respuesta !== null) {
                $respuesta = $decision->respuesta;
                $io->writeln('     <comment>(resuelto en el triaje, sin herramientas)</comment>');
            } else {
                $tramo = $decision->tipo === TipoDeMensaje::Emergencia
                    ? PotenciaRequerida::Alta
                    : PotenciaRequerida::Media;

                $elegido = $this->potencias->elegir($tramo);

                if ($elegido === null) {
                    $io->error(sprintf('Sin proveedor con credenciales para el tramo %s.', $tramo->value));
                    return Command::FAILURE;
                }

                $salida = $elegido->motor->conversar(new ConversationRequest(
                    actor: $actor,
                    systemPrompt: (string) $reglas->invoke($this->procesador),
                    mensaje: $mensaje,
                    historial: $historial,
                    permitirEscritura: false,
                    maxTokens: 1024,
                    modelo: $elegido->modelo,
                    contexto: (string) $contextoDe->invoke($this->procesador, $conversacion, $decision),
                ));

                $respuesta = $salida->tieneTexto() ? (string) $salida->texto : sprintf('(sin texto: %s)', $salida->motivo);
                $io->writeln(sprintf('     <comment>modelo: %s · motivo: %s</comment>', (string) $elegido->modelo, (string) $salida->motivo));
            }

            $io->writeln(sprintf("    <fg=green>🤖 %s</>", str_replace("\n", "\n       ", trim((string) $respuesta))));

            $historial[] = ['rol' => 'usuario', 'texto' => $mensaje];
            $historial[] = ['rol' => 'asistente', 'texto' => (string) $respuesta];
        }

        $io->newLine();
        $io->success('Nada de esto se ha guardado: el historial vivió en memoria.');

        return Command::SUCCESS;
    }

    /**
     * ¿Se puede correr sin despertar a nadie?
     *
     * Se comprueba ANTES de la primera llamada al modelo: descubrirlo a mitad de la charla es
     * descubrirlo cuando el WhatsApp ya salió.
     */
    private function guardiaDespejada(SymfonyStyle $io, bool $forzar): bool
    {
        $guardias = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.roles LIKE :rol')->setParameter('rol', '%CUSTOMER_SUPPORT%')
            ->andWhere('u.telefono IS NOT NULL')
            ->andWhere("u.telefono <> ''")
            ->getQuery()->getResult();

        if ($guardias === []) {
            return true;
        }

        $nombres = implode(', ', array_map(static fn (User $u) => $u->getUserIdentifier(), $guardias));

        if ($forzar) {
            $io->warning(sprintf('Sigues con guardia activa (%s): si el modelo escala, les llega el aviso.', $nombres));
            return true;
        }

        $io->error(sprintf(
            "Hay gente de guardia con móvil (%s). Si el modelo llama a escalar_al_equipo les llega "
            . "un WhatsApp de verdad.\nVacía esos teléfonos mientras pruebas, o repite con --con-guardia.",
            $nombres
        ));

        return false;
    }

    /**
     * Los mensajes a reproducir: los de un archivo, o los que entraron en la conversación.
     *
     * @return list<string>
     */
    private function guion(?string $ruta, MessageConversation $conversacion): array
    {
        if ($ruta !== null) {
            $crudo = @file_get_contents($ruta);
            $datos = $crudo === false ? null : json_decode($crudo, true);

            return is_array($datos)
                ? array_values(array_filter(array_map('strval', $datos), static fn (string $m) => trim($m) !== ''))
                : [];
        }

        $mensajes = [];

        foreach ($conversacion->getMessages() as $m) {
            if ($m->getDirection() !== Message::DIRECTION_INCOMING) {
                continue;
            }

            $texto = trim((string) ($m->getContentExternal() ?? $m->getContentLocal() ?? ''));

            if ($texto !== '') {
                $mensajes[] = $texto;
            }
        }

        return $mensajes;
    }
}
