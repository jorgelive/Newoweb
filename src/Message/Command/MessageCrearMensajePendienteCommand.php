<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use App\Message\Service\Queue\MensajeEnEsperaDeVentana;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La plantilla que pide permiso para mandar lo que el operador dejó esperando.
 *
 * Sin variables a propósito: vale para cualquier hilo —reserva, cotización o un número suelto—
 * sin depender de que su contexto tenga resolutor de datos, y Meta la revisa por un texto fijo que
 * es exactamente lo que se envía. El botón es una **respuesta rápida**, no un enlace: es lo que
 * abre la ventana de 24 h (un botón de enlace no le manda nada al negocio).
 *
 * ```
 * php bin/console msg:plantillas:mensaje-pendiente
 * php bin/console msg:meta:push mensaje_pendiente --todos
 * php bin/console app:whatsapp:sync-templates          # cuando Meta la apruebe
 * ```
 *
 * Ver `MensajeEnEsperaDeVentana` y docs/Mensajeria.md §5.
 */
#[AsCommand(
    name: 'msg:plantillas:mensaje-pendiente',
    description: 'Crea la plantilla que pide permiso para enviar un mensaje que espera la ventana de WhatsApp.',
)]
final class MessageCrearMensajePendienteCommand extends Command
{
    private const string CUERPO = 'Hola, soy Susan 😊 Tengo una respuesta para ti. ¿Te la envío por aquí?';

    private const string BOTON = 'Sí, envíamela';

    private const string AGENTE_USO = 'Interna: la manda SOLA el sistema cuando un operador deja un mensaje esperando '
        . 'a que se abra la ventana de WhatsApp. No la envíes tú.';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codigo = MensajeEnEsperaDeVentana::PLANTILLA;

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]) !== null) {
            // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
            $io->note(sprintf('«%s» ya existe: no se toca.', $codigo));

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Simulación: se crearía «%s».', $codigo));

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];

        $plantilla = (new MessageTemplate())
            ->setCode($codigo)
            ->setName('Mensaje en espera (aviso)')
            // Sin contexto ni origen: sirve en cualquier hilo.
            ->setContextType(null)
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(self::AGENTE_USO)
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: ver §18 de docs/Mensajeria.md.
                'meta_template_name' => $codigo . '_v1',
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => $cuerpo,
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'quick_reply',
                    'content' => null,
                    'resolver_key' => MensajeEnEsperaDeVentana::PAYLOAD_BOTON,
                    'button_text' => [['language' => 'es', 'content' => self::BOTON]],
                ]],
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas que faltan.
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', $codigo, $codigo));

        return Command::SUCCESS;
    }
}
