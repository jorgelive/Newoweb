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
 * La plantilla con la que se le manda una solicitud de servicio a un proveedor con la ventana de
 * WhatsApp cerrada (`solicitud_servicio_proveedor`).
 *
 * Una orden no cabe en una plantilla —tiene tantas líneas como servicios—, así que lleva lo que le
 * deja actuar: **el enlace** a la solicitud entera y un botón para **recibirla aquí**. Ése es una
 * respuesta rápida con el payload de siempre (`CMD_ENVIAR_PENDIENTE`): pulsarlo abre la ventana y
 * `MensajeEnEsperaDeVentana::liberar()` suelta el texto completo, que esperaba en el hilo. Hay quien
 * prefiere tenerlo todo en su WhatsApp (Jorge, 19/10/2026).
 *
 * El texto lo aprobó Jorge el 19/10/2026. Sólo en español: los proveedores son de aquí. Variables:
 * `guest_name` (el saludo: «Gabriel Ayme» o «equipo de Tunupa»), `numero`, `resumen` y, en el botón
 * de enlace, `orden_token`.
 *
 * ```
 * php bin/console msg:plantillas:solicitud-proveedor
 * php bin/console msg:meta:push solicitud_servicio_proveedor --idiomas=es
 * php bin/console app:whatsapp:sync-templates          # cuando Meta la apruebe
 * ```
 *
 * Ver `OperacionOrdenEnvio` y docs/Operacion.md §5.7.
 */
#[AsCommand(
    name: 'msg:plantillas:solicitud-proveedor',
    description: 'Crea la plantilla de solicitud de servicio al proveedor fuera de la ventana de WhatsApp.',
)]
final class MessageCrearSolicitudProveedorCommand extends Command
{
    public const string CODIGO = 'solicitud_servicio_proveedor';

    private const string CUERPO = 'Hola {{guest_name}}, le enviamos la solicitud de servicio {{numero}} para {{resumen}}. '
        . 'Puede verla completa en el enlace o pedirnos que se la enviemos por aquí. '
        . 'Por favor, confírmenos recepción y disponibilidad.';

    /** Meta corta en 25 caracteres. La respuesta rápida delante y el enlace detrás, como el aviso de salida aprobado. */
    private const string BOTON_AQUI = 'Envíenmelo aquí';
    private const string BOTON_ENLACE = 'Ver solicitud';

    private const string AGENTE_USO = 'Interna: la manda SOLA el sistema al enviar una orden de servicio a un proveedor '
        . 'con la ventana de WhatsApp cerrada. No la envíes tú.';

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

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]) !== null) {
            // Idempotente y sin pisar: puede haberla afinado alguien desde el panel.
            $io->note(sprintf('«%s» ya existe: no se toca.', self::CODIGO));

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Simulación: se crearía «%s».', self::CODIGO));

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];

        $plantilla = (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Solicitud de servicio al proveedor (ventana cerrada)')
            ->setContextType(null)
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            // La manda sólo el sistema: no se ofrece en los menús de plantillas.
            ->setEnvioManual(false)
            ->setAgenteUso(self::AGENTE_USO)
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: ver §18 de docs/Mensajeria.md.
                'meta_template_name' => self::CODIGO . '_v1',
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => $cuerpo,
                'buttons_map' => [
                    [
                        'index' => 0,
                        'type' => 'quick_reply',
                        'content' => null,
                        'resolver_key' => MensajeEnEsperaDeVentana::PAYLOAD_BOTON,
                        'button_text' => [['language' => 'es', 'content' => self::BOTON_AQUI]],
                    ],
                    [
                        'index' => 1,
                        'type' => 'url',
                        'content' => 'https://pax.openperu.pe/orden/{{1}}',
                        'resolver_key' => 'orden_token',
                        'button_text' => [['language' => 'es', 'content' => self::BOTON_ENLACE]],
                    ],
                ],
                // Lo que Meta ve en cada variable: un caso real, no «Dato_Ejemplo».
                'ejemplos' => ['es' => [
                    'guest_name' => 'Gabriel Ayme',
                    'numero' => 'OS-20261019-976',
                    'resumen' => '2 servicios, del 19 al 24 de octubre',
                ]],
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        $this->em->flush();

        // `AutoTranslate` reescribe los botones desde el español al crear (ver
        // `MessageCrearMensajePendienteCommand`). Aquí sólo hay español, pero se vuelven a poner
        // por si el traductor los tocó, conservando su `origenHash`.
        $meta = $plantilla->getWhatsappMetaTmpl() ?? [];
        foreach ($meta['buttons_map'] ?? [] as $i => $boton) {
            foreach ($boton['button_text'] ?? [] as $j => $texto) {
                if (($texto['language'] ?? '') === 'es') {
                    $meta['buttons_map'][$i]['button_text'][$j]['content'] = $i === 0 ? self::BOTON_AQUI : self::BOTON_ENLACE;
                }
            }
        }
        $plantilla->setWhatsappMetaTmpl($meta);
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --idiomas=es', self::CODIGO, self::CODIGO));

        return Command::SUCCESS;
    }
}
