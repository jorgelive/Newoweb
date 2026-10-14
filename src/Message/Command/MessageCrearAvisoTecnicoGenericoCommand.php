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
 * La plantilla con la que se avisa al equipo de una avería técnica que NO afecta a los huéspedes.
 *
 * ── Por qué no vale la que ya había ─────────────────────────────────────────
 * `aviso_tecnico_interno` se escribió para el motor de IA caído, y su cuerpo aprobado lleva fijo
 * «a los huéspedes que escriben les contesta un mensaje automático de cortesía». Para cualquier
 * otra avería —el tipo de cambio sin actualizar, el primero— esa frase es falsa, y mandar por Meta
 * un texto aprobado que miente es motivo de bloqueo además de confundir a quien lo lee.
 *
 * Sin plantilla, el aviso del tipo de cambio **casi nunca salía por WhatsApp**: sólo se puede
 * mandar texto libre a quien escribió al número en las últimas 24 h, y quien está de guardia no
 * lo hace a diario. Caía al push a operaciones, que es el canal que nadie miró del 09 al
 * 13/10/2026 mientras la cuota de decolecta estaba agotada. Lo vio la revisión de Fable del
 * 13/10/2026.
 *
 * ── Genérica de verdad ──────────────────────────────────────────────────────
 * Dos huecos, `{{sistema}}` y `{{motivo}}`, y nada que suponga qué está viendo el huésped. Vale
 * para el tipo de cambio, el latido de la domótica cuando exista (`docs/PlanDomoticaLocal.md` §6)
 * y lo que venga. ⚠️ Un parámetro por línea: Meta no admite saltos en las variables.
 *
 * Nace `hidden` porque es de una vez. Idempotente por el código.
 *
 *   php bin/console msg:crear:aviso-tecnico-generico --dry-run
 *   php bin/console msg:crear:aviso-tecnico-generico
 *   php bin/console msg:meta:push aviso_tecnico_generico_interno --todos
 *
 * La marca de oficial la pone sola `app:whatsapp:sync-templates` (cron de las 03:15) cuando Meta
 * la aprueba; hasta entonces el envío fuera de ventana falla y el aviso cae al push.
 */
#[AsCommand(
    name: 'msg:crear:aviso-tecnico-generico',
    description: 'Crea la plantilla «aviso_tecnico_generico_interno» para averías que no tocan al huésped. Idempotente.',
    hidden: true,
)]
final class MessageCrearAvisoTecnicoGenericoCommand extends Command
{
    public const string CODIGO = 'aviso_tecnico_generico_interno';

    /** Con sufijo desde el primer día: el nombre en Meta no se edita (docs/Mensajeria.md §18). */
    private const string NOMBRE_META = self::CODIGO . '_v1';

    /**
     * Qué falla, por qué y qué hacer. Empieza y acaba con texto fijo: Meta rechaza un cuerpo que
     * empieza o termina en una variable.
     */
    private const string CUERPO = <<<'TXT'
        ⚠️ *{{sistema}}* necesita atención.

        Motivo: {{motivo}}

        No afecta a lo que ven los huéspedes, pero conviene revisarlo hoy.
        TXT;

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
            $io->success(sprintf('«%s» ya existe.', self::CODIGO));

            return Command::SUCCESS;
        }

        $io->section('Se creará');
        $io->writeln(self::CUERPO);
        $io->newLine();
        $io->text('Cabecera: «Aviso técnico» · Pie: el de las internas · Categoría UTILITY');

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $plantilla = (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Aviso interno técnico (genérico)')
            // `staff`, como sus hermanas: no cuelga de ninguna reserva.
            ->setContextType('staff')
            ->setAutoenvioHabilitada(false)
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                'meta_template_name' => self::NOMBRE_META,
                // `false` hasta que Meta la apruebe; lo pone a `true` la sincronización diaria.
                'is_official_meta' => false,
                'header' => [['format' => 'TEXT', 'language' => 'es', 'content' => 'Aviso técnico']],
                // El mismo pie a mano en siete idiomas, con «Sistema OpenPeru» sin traducir: ver
                // por qué en `MessageCrearAvisoTecnicoCommand::PIE`.
                'footer' => MessageCrearAvisoTecnicoCommand::PIE,
                'body' => [['language' => 'es', 'content' => self::CUERPO]],
                // Sin botones: la acción es mirar un log o un panel, no una URL.
                'buttons_map' => [],
            ])
            ->setWhatsappLinkTmpl(['is_active' => false, 'body' => []])
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        $this->em->flush();

        $io->success(sprintf('«%s» creada.', self::CODIGO));
        $io->note(sprintf('Falta subirla: php bin/console msg:meta:push %s --todos', self::CODIGO));

        return Command::SUCCESS;
    }
}
