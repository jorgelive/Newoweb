<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea `aviso_fusion_sugerida_interno`: el teléfono o el correo de un huésped ya es de otra
 * conversación, y el equipo tiene que decir si son la misma persona.
 *
 * Es el respaldo FUERA de la ventana de 24 h; dentro, sale el texto que redacta
 * `AvisarFusionSugeridaDispatchHandler`, que además cuenta los avisos atascados. Botón «Abrir el
 * chat» al hilo, donde está el banner con «Unir» y «No es la misma persona». Ver
 * `docs/Mensajeria.md`, «El choque de identificadores que nadie veía».
 *
 *   php bin/console msg:crear:aviso-fusion --dry-run
 *   php bin/console msg:crear:aviso-fusion
 *   php bin/console msg:meta:push aviso_fusion_sugerida_interno --todos
 */
#[AsCommand(
    name: 'msg:crear:aviso-fusion',
    description: 'Crea la plantilla «aviso_fusion_sugerida_interno»: dos conversaciones que parecen la misma persona. Idempotente.',
    hidden: true,
)]
final class MessageCrearAvisoFusionCommand extends Command
{
    public const string CODIGO = 'aviso_fusion_sugerida_interno';

    private const string CUERPO = '🔗 El teléfono o el correo de *{{huesped}}* ya está en la conversación de *{{otro}}*. '
        . 'Si son la misma persona, únelas desde el chat; si no, márcalo como otra persona.';

    /** Las cabeceras y pies de todas las internas, a mano: ver `MessageCrearAvisoTecnicoCommand::PIE`. */
    private const array CABECERA = [
        ['format' => 'TEXT', 'language' => 'es', 'content' => 'Aviso interno'],
        ['format' => 'TEXT', 'language' => 'en', 'content' => 'Internal alert'],
        ['format' => 'TEXT', 'language' => 'pt', 'content' => 'Aviso interno'],
        ['format' => 'TEXT', 'language' => 'fr', 'content' => 'Avis interne'],
        ['format' => 'TEXT', 'language' => 'it', 'content' => 'Avviso interno'],
        ['format' => 'TEXT', 'language' => 'de', 'content' => 'Interne Mitteilung'],
        ['format' => 'TEXT', 'language' => 'nl', 'content' => 'Interne mededeling'],
    ];

    private const array PIE = [
        ['language' => 'es', 'content' => 'Aviso automático · Sistema OpenPeru'],
        ['language' => 'en', 'content' => 'Automatic alert · Sistema OpenPeru'],
        ['language' => 'pt', 'content' => 'Alerta automático · Sistema OpenPeru'],
        ['language' => 'fr', 'content' => 'Alerte automatique · Sistema OpenPeru'],
        ['language' => 'it', 'content' => 'Avviso automatico · Sistema OpenPeru'],
        ['language' => 'de', 'content' => 'Automatische Meldung · Sistema OpenPeru'],
        ['language' => 'nl', 'content' => 'Automatische melding · Sistema OpenPeru'],
    ];

    /** Los del botón, a mano: el traductor los pisa al guardar. Son los de `aviso_escalado_interno`. */
    private const array BOTON = [
        'es' => 'Abrir el chat', 'en' => 'Open the chat', 'pt' => 'Abrir bate-papo', 'fr' => 'Ouvrir le chat',
        'it' => 'Apri la chat', 'de' => 'Chat öffnen', 'nl' => 'Open chat',
    ];

    /** Lo que Meta revisa en cada hueco: un caso real, no «Dato_Ejemplo». */
    private const array EJEMPLO = [
        'huesped' => 'Anna Müller',
        'otro' => 'Anna Muller',
    ];

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

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]) !== null) {
            $io->success(sprintf('«%s» ya existe: no se toca.', self::CODIGO));

            return Command::SUCCESS;
        }

        $io->section(sprintf('Se creará «%s»', self::CODIGO));
        $io->writeln(self::CUERPO);

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $ejemplos = [];
        foreach (self::PIE as $fila) {
            $ejemplos[$fila['language']] = self::EJEMPLO;
        }

        $plantilla = (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Aviso interno: dos conversaciones que parecen la misma persona')
            ->setContextType('staff')
            ->setAutoenvioHabilitada(false)
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => self::CODIGO . '_v1',
                'is_official_meta' => false,
                'header' => self::CABECERA,
                'footer' => self::PIE,
                'body' => [['language' => 'es', 'content' => self::CUERPO]],
                'buttons_map' => [[
                    'index' => 0,
                    'type' => 'url',
                    'content' => 'https://util.openperu.pe/{{1}}',
                    'resolver_key' => 'chat_path',
                    'button_text' => [['language' => 'es', 'content' => self::BOTON['es']]],
                ]],
                'ejemplos' => $ejemplos,
            ])
            ->setWhatsappLinkTmpl(['is_active' => false, 'body' => []])
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist`: traduce el cuerpo y PISA el botón.
        $this->em->flush();

        $this->botonAMano($plantilla);
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', self::CODIGO, self::CODIGO));

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
