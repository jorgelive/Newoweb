<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use App\Cotizacion\Service\Message\CotizacionAvisosDeCobro;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea `saldo_pendiente_viaje`: el aviso al cliente de un VIAJE de que ya puede pagar el saldo, media
 * hora después de pagar el adelanto con tarjeta (16/10/2026). La manda
 * {@see CotizacionAvisosDeCobro::saldoPendiente()} desde `app:cotizacion:saldo-tras-adelanto`.
 *
 * Hermana de `saldo_pendiente` y no la misma: aquélla dice «el saldo de tu reserva… a más tardar el
 * día de tu llegada… en tu guía», que en un viaje es falso. Oficial de Meta por lo mismo que aquélla:
 * casi nunca hay ventana de 24 h abierta. El botón lleva a la propuesta en pax (`account_path` de
 * `CotizacionMessageDataResolver`: `file/{loc}/p/{n}`), cuyo «Cómo reservar» enseña el enlace vivo.
 *
 *   php bin/console msg:plantillas:saldo-pendiente-viaje --dry-run
 *   php bin/console msg:plantillas:saldo-pendiente-viaje
 *   php bin/console msg:meta:push saldo_pendiente_viaje --todos      ← mandarla a Meta: decisión de Jorge
 */
#[AsCommand(
    name: 'msg:plantillas:saldo-pendiente-viaje',
    description: 'Crea la plantilla «saldo_pendiente_viaje»: el saldo del viaje listo para pagar tras el adelanto. Idempotente.',
)]
final class MessageCrearSaldoPendienteViajeCommand extends Command
{
    private const string INICIO = "Hola {{guest_name}} 👋 Tu adelanto ya está registrado, ¡gracias! El saldo de tu viaje es de {{importe_saldo}}.\n\n"
        . 'Puedes pagarlo cuando prefieras, a más tardar el día del primer servicio: con tarjeta o con cualquiera de los medios de pago de tu cotización';

    private const string CUERPO = self::INICIO . ".\n\n👉 {{account_url}}";

    private const string CUERPO_META = self::INICIO . ".\n\n👇 Ábrela con el botón de abajo.";

    /** Los del botón, a mano: el traductor los pisa al guardar. Tope de Meta: 25. */
    private const array BOTON = [
        'es' => 'Pagar el saldo', 'en' => 'Pay the balance', 'pt' => 'Pagar o saldo', 'fr' => 'Payer le solde',
        'it' => 'Paga il saldo', 'de' => 'Restbetrag zahlen', 'nl' => 'Saldo betalen',
    ];

    private const string AGENTE_USO = 'Interna: la manda SOLA el sistema media hora después de que el cliente de un viaje pague el adelanto con tarjeta. No la envíes tú.';

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
        $codigo = CotizacionAvisosDeCobro::PLANTILLA_SALDO;

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
            // Un saldo de viaje real (EG95UF, 16/10/2026): Meta revisa un caso de verdad.
            $ejemplos[$idioma] = ['guest_name' => 'Omar', 'importe_saldo' => 'USD 459.72'];
        }

        $plantilla = (new MessageTemplate())
            ->setCode($codigo)
            ->setName('Saldo del viaje listo para pagar, tras el adelanto (al cliente)')
            ->setContextType('cotizacion_file')
            ->setAllowedSources([])
            ->setAutoenvioHabilitada(false)
            // La manda sólo el sistema: no se ofrece en los menús de plantillas.
            ->setEnvioManual(false)
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
