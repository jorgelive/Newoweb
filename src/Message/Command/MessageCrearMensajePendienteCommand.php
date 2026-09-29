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
 * Las dos plantillas que piden permiso para mandar lo que el operador dejó esperando: la que
 * nombra la operación (`respuesta_pendiente`, preferida) y la genérica (`mensaje_pendiente`).
 *
 * La genérica va sin variables: vale para cualquier hilo —reserva, cotización o un número suelto—
 * sin depender de que su contexto tenga resolutor de datos, y Meta la revisa por un texto fijo que
 * es exactamente lo que se envía. El botón es una **respuesta rápida**, no un enlace: es lo que
 * abre la ventana de 24 h (un botón de enlace no le manda nada al negocio).
 *
 * ```
 * php bin/console msg:plantillas:mensaje-pendiente        # crea las dos (idempotente)
 * php bin/console msg:meta:push mensaje_pendiente --todos
 * php bin/console msg:meta:push respuesta_pendiente --todos
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

    private const string AGENTE_USO = 'Interna: la manda SOLA el sistema cuando un operador deja un mensaje esperando '
        . 'a que se abra la ventana de WhatsApp. No la envíes tú.';

    /**
     * La que nombra la operación. Sin emoji y en plural a propósito: la v1, genérica y en primera
     * persona, la pasó Meta de UTILITY a MARKETING el 28/09/2026. Ver
     * `MensajeEnEsperaDeVentana::PLANTILLA_CON_REFERENCIA`.
     */
    private const string CUERPO_CON_REFERENCIA = 'Hola {{guest_name}}, ya tenemos la respuesta sobre {{referencia}}. ¿Te la enviamos por aquí?';

    /**
     * El botón, escrito a mano en cada idioma: la traducción automática los dejaba largos (el
     * neerlandés en 28 caracteres) y Meta corta en 25.
     */
    private const array BOTONES = [
        'es' => 'Sí, envíamela', 'en' => 'Yes, send it', 'pt' => 'Sim, pode enviar', 'fr' => 'Oui, envoie-la',
        'it' => 'Sì, mandamela', 'de' => 'Ja, gerne', 'nl' => 'Ja, stuur maar',
    ];

    /**
     * Lo que Meta revisa en `{{referencia}}`: un caso de uso real, no «Dato_Ejemplo». Tours, que es
     * el caso que lo trajo (la cotización de Eduardo), y la prueba de que responde a algo pedido.
     */
    private const array EJEMPLOS_REFERENCIA = [
        'es' => 'los tours que pediste', 'en' => 'the tours you asked about', 'pt' => 'os passeios que você pediu',
        'fr' => 'les excursions que tu as demandées', 'it' => 'i tour che hai chiesto',
        'de' => 'die Touren, nach denen Sie gefragt haben', 'nl' => 'de tours waar je om vroeg',
    ];

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
        $simular = (bool) $input->getOption('dry-run');

        $this->crear($io, $simular, MensajeEnEsperaDeVentana::PLANTILLA, 'Mensaje en espera (aviso)', self::CUERPO, []);
        $this->crear(
            $io,
            $simular,
            MensajeEnEsperaDeVentana::PLANTILLA_CON_REFERENCIA,
            'Mensaje en espera (aviso con referencia)',
            self::CUERPO_CON_REFERENCIA,
            array_map(static fn (string $ejemplo): array => ['guest_name' => 'Eduardo', 'referencia' => $ejemplo], self::EJEMPLOS_REFERENCIA)
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array<string, string>> $ejemplos Por idioma: lo que Meta ve en cada variable.
     */
    private function crear(SymfonyStyle $io, bool $simular, string $codigo, string $nombre, string $texto, array $ejemplos): void
    {

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]) !== null) {
            // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
            $io->note(sprintf('«%s» ya existe: no se toca.', $codigo));

            return;
        }

        if ($simular) {
            $io->note(sprintf('Simulación: se crearía «%s».', $codigo));

            return;
        }

        $cuerpo = [['language' => 'es', 'content' => $texto]];
        $botones = [];

        foreach (self::BOTONES as $idioma => $boton) {
            $botones[] = ['language' => $idioma, 'content' => $boton];
        }

        $plantilla = (new MessageTemplate())
            ->setCode($codigo)
            ->setName($nombre)
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
                    'button_text' => $botones,
                ]],
                'ejemplos' => $ejemplos,
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas que faltan.
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', $codigo, $codigo));
    }
}
