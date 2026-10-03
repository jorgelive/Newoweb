<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use App\Pms\Service\Message\PagoRecibido;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea `pago_recibido`: la confirmación al huésped de que su pago con tarjeta entró, con el
 * enlace a su estado de cuenta. La manda {@see PagoRecibido} al confirmarse el cobro.
 *
 * Oficial de Meta porque el pago llega a cualquier hora y la ventana de 24 h casi nunca está
 * abierta: se paga desde un enlace que se le mandó días antes. Por Beds24, el mismo texto con el
 * enlace escrito (en Booking ya no hay teléfono). `{{importe_abonado}}` y `{{comision_pasarela}}`
 * los pone el mensaje, no la reserva: código de moneda y cifra, sin palabras, para que valgan en los
 * siete idiomas. Neto + comisión = lo cobrado a la tarjeta: cuadra con su cuenta y con su banco.
 *
 *   php bin/console msg:plantillas:pago-recibido --dry-run
 *   php bin/console msg:plantillas:pago-recibido
 *   php bin/console msg:meta:push pago_recibido --todos
 */
#[AsCommand(
    name: 'msg:plantillas:pago-recibido',
    description: 'Crea la plantilla «pago_recibido»: confirmación al huésped de su pago con tarjeta. Idempotente.',
    hidden: true,
)]
final class MessageCrearPagoRecibidoCommand extends Command
{
    private const string CUERPO = "✅ ¡Hola {{guest_name}}! Hemos recibido tu pago de {{importe_abonado}} + {{comision_pasarela}} de comisión de la pasarela de pago. ¡Muchas gracias!\n\n"
        . 'Puedes ver tu estado de cuenta actualizado aquí: {{account_url}}';

    private const string CUERPO_META = "✅ ¡Hola {{guest_name}}! Hemos recibido tu pago de {{importe_abonado}} + {{comision_pasarela}} de comisión de la pasarela de pago. ¡Muchas gracias!\n\n"
        . 'Puedes ver tu estado de cuenta actualizado con el botón de abajo.';

    /** Los del botón, a mano: el traductor los pisa al guardar. Tope de Meta: 25. */
    private const array BOTON = [
        'es' => 'Ver estado de cuenta', 'en' => 'View my account', 'pt' => 'Ver minha conta', 'fr' => 'Voir mon compte',
        'it' => 'Vedi il mio conto', 'de' => 'Mein Konto ansehen', 'nl' => 'Mijn rekening bekijken',
    ];

    private const string AGENTE_USO = 'Interna: la manda SOLA el sistema al confirmarse un pago con tarjeta. No la envíes tú.';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué crearía');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $codigo = PagoRecibido::PLANTILLA;

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]) !== null) {
            $io->success(sprintf('«%s» ya existe: no se toca.', $codigo));

            return Command::SUCCESS;
        }

        $io->section(sprintf('Se creará «%s»', $codigo));
        $io->writeln(['Beds24 / dentro de la ventana:', self::CUERPO, '', 'Meta (oficial), con botón:', self::CUERPO_META]);

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];
        $ejemplos = [];
        foreach (array_keys(self::BOTON) as $idioma) {
            $ejemplos[$idioma] = ['guest_name' => 'Anna', 'importe_abonado' => 'USD 51.32', 'comision_pasarela' => 'USD 2.82'];
        }

        $plantilla = (new MessageTemplate())
            ->setCode($codigo)
            ->setName('Pago con tarjeta recibido (al huésped)')
            ->setContextType('pms_reserva')
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            ->setAgenteUso(self::AGENTE_USO)
            ->setBeds24Tmpl(['is_active' => true, 'disable_meta_buttons' => true, 'body' => $cuerpo])
            // Dentro de la ventana, el texto con el enlace escrito: el botón sobraría.
            ->setWhatsappLinkTmpl(['disable_meta_buttons' => true, 'body' => $cuerpo])
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => $codigo . '_v1',
                'is_official_meta' => false,
                'header' => [],
                'footer' => [],
                'body' => [['language' => 'es', 'content' => self::CUERPO_META]],
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'url',
                    'content' => 'https://pax.openperu.pe/{{1}}',
                    'resolver_key' => 'account_path',
                    'button_text' => [['language' => 'es', 'content' => self::BOTON['es']]],
                ]],
                'ejemplos' => $ejemplos,
            ])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist`: traduce los cuerpos y PISA el botón.
        $this->em->flush();

        $this->botonAMano($plantilla);
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Revisa las traducciones y súbela con: msg:meta:push %s --todos', $codigo, $codigo));

        return Command::SUCCESS;
    }

    /** El botón en cada idioma, conservando el `origenHash` que le dejó el traductor. */
    private function botonAMano(MessageTemplate $plantilla): void
    {
        $meta = $plantilla->getWhatsappMetaTmpl() ?? [];
        $hashes = [];

        foreach ($meta['buttons_map'][0]['button_text'] ?? [] as $texto) {
            $hashes[$texto['language'] ?? ''] = $texto['origenHash'] ?? null;
        }

        $traducidos = [];
        foreach (self::BOTON as $idioma => $texto) {
            $fila = ['language' => $idioma, 'content' => $texto];
            if (($hashes[$idioma] ?? null) !== null) {
                $fila['origenHash'] = $hashes[$idioma];
            }
            $traducidos[] = $fila;
        }

        $meta['buttons_map'][0]['button_text'] = $traducidos;
        $plantilla->setWhatsappMetaTmpl($meta);
    }
}
