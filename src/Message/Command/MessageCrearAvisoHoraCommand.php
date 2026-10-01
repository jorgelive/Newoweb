<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Agent\Skill\Pms\ConfirmarHoraSkill;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La plantilla con la que se avisa al equipo de que un huésped confirmó su hora de llegada o de
 * salida (`confirmar_hora`), para cuando el aviso cae fuera de la ventana de 24 h.
 *
 * El equipo se entera de TODA confirmación, también de la hora por defecto: «salgo a las 10» no
 * se distingue de no haber dicho nada si nadie lo cuenta (Jorge, 01/10/2026). Texto aprobado por
 * Jorge el mismo día. Lo que necesita una decisión —una hora fuera del horario— no va por aquí: va
 * con la plantilla del escalado.
 *
 *   php bin/console msg:crear:aviso-hora
 *   php bin/console msg:meta:push aviso_hora_confirmada_interno --todos
 */
#[AsCommand(
    name: 'msg:crear:aviso-hora',
    description: 'Crea la plantilla «aviso_hora_confirmada_interno»: un huésped confirmó su hora. Idempotente.',
    hidden: true,
)]
final class MessageCrearAvisoHoraCommand extends Command
{
    /**
     * ⚠️ Dos variables y no cuatro. La primera versión —«🕐 *{{huesped}}* confirma que {{accion}} el
     * {{fecha}} a las {{hora}}.»— la rechazó Meta al subirla: «demasiadas variables para su
     * longitud». Qué hace y cuándo viaja junto, en `{{detalle}}`.
     */
    private const string CUERPO = '🕐 *{{huesped}}* ha confirmado su horario: {{detalle}}. Ya queda anotado en su reserva.';

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

    /** Lo que Meta revisa en cada hueco: un caso real, no «Dato_Ejemplo». */
    private const array EJEMPLO = [
        'huesped' => 'Anna Müller (Casita 1, UV5XPW)',
        'detalle' => 'llega el 02/02 a las 16:00',
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
        $codigo = ConfirmarHoraSkill::PLANTILLA_CONFIRMADA;

        if ($this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $codigo]) !== null) {
            $io->success(sprintf('«%s» ya existe: no se toca.', $codigo));

            return Command::SUCCESS;
        }

        $io->section(sprintf('Se creará «%s»', $codigo));
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
            ->setCode($codigo)
            ->setName('Aviso interno: un huésped confirmó su hora')
            ->setContextType('staff')
            ->setAutoenvioHabilitada(false)
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => $codigo . '_v1',
                'is_official_meta' => false,
                'header' => self::CABECERA,
                'footer' => self::PIE,
                'body' => [['language' => 'es', 'content' => self::CUERPO]],
                'buttons_map' => [],
                'ejemplos' => $ejemplos,
            ])
            ->setWhatsappLinkTmpl(['is_active' => false, 'body' => []])
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        // `AutoTranslate` corre en `prePersist` y rellena los seis idiomas del cuerpo.
        $this->em->flush();

        $io->success(sprintf('«%s» creada. Súbela con: msg:meta:push %s --todos', $codigo, $codigo));

        return Command::SUCCESS;
    }
}
