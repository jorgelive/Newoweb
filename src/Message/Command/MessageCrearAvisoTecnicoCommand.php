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
 * La plantilla con la que se avisa al equipo de que algo NUESTRO se ha roto.
 *
 * ── Por qué hace falta una más ──────────────────────────────────────────────
 * WhatsApp sólo deja texto libre a quien escribió al número en las últimas 24 h, y quien está
 * de guardia no le escribe al número del negocio todos los días. Fuera de esa ventana hace
 * falta plantilla aprobada, y las dos internas que existen hablan de otra cosa:
 * `aviso_escalado_interno` dice «*{{huesped}}* está esperando respuesta» y `aviso_cobro_interno`
 * habla de un pago. Usar cualquiera de las dos para una avería sería mentir en el texto que
 * Meta aprobó, que además es motivo de bloqueo.
 *
 * Lo que la motivó: el 19/09/2026 la API de Google se quedó sin crédito y el agente pasó 31
 * horas contestando a los huéspedes con la frase de cortesía. Nadie se enteró. Ahora
 * {@see \App\Agent\Service\VigilanteDelMotor} avisa al grupo de seguridad `soporte`, pero si la
 * avería cae un domingo y nadie ha escrito al número, ese aviso no sale.
 *
 * ── Genérica a propósito ────────────────────────────────────────────────────
 * No se llama «motor de IA caído»: los dos huecos —`{{sistema}}` y `{{motivo}}`— valen para
 * cualquier alarma técnica que venga después, las colas atascadas incluidas. Una plantilla por
 * avería serían cuatro semanas de aprobación cada vez que aparezca una nueva.
 *
 * ⚠️ **Los parámetros van en UNA línea**, que es lo que exige Meta: `VigilanteDelMotor` ya
 * aplasta los saltos del mensaje del proveedor antes de pasarlo.
 *
 * Nace `hidden` porque es de una vez. Idempotente por el código.
 *
 *   php bin/console msg:crear:aviso-tecnico --dry-run
 *   php bin/console msg:crear:aviso-tecnico
 *   php bin/console msg:meta:push aviso_tecnico_interno --idiomas=es
 */
#[AsCommand(
    name: 'msg:crear:aviso-tecnico',
    description: 'Crea la plantilla «aviso_tecnico_interno» para las alarmas al equipo. Idempotente.',
    hidden: true,
)]
final class MessageCrearAvisoTecnicoCommand extends Command
{
    private const string CODIGO = 'aviso_tecnico_interno';

    /**
     * El cuerpo. Dice QUÉ pasa, QUÉ está viendo el huésped mientras tanto y QUÉ hacer.
     *
     * Lo del huésped no es relleno: es lo que decide si hay que levantarse de la mesa o puede
     * esperar a mañana. Un «algo ha fallado» sin consecuencia visible se mira cuando se puede.
     */
    private const string CUERPO = <<<'TXT'
        🤖 *{{sistema}}* no está respondiendo.

        Motivo: {{motivo}}

        Mientras tanto, a los huéspedes que escriben les contesta un mensaje automático de cortesía y nadie más les responde. Conviene mirarlo ya.
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
        $simular = (bool) $input->getOption('dry-run');

        $existente = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if ($existente !== null) {
            $io->success(sprintf('«%s» ya existe: no se toca.', self::CODIGO));

            return Command::SUCCESS;
        }

        $cuerpo = [['language' => 'es', 'content' => self::CUERPO]];

        $io->section('Se creará');
        $io->writeln(self::CUERPO);
        $io->newLine();
        $io->text('Cabecera: «Aviso técnico» · Pie: «Aviso automático del PMS» · Categoría UTILITY');

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $plantilla = (new MessageTemplate())
            ->setCode(self::CODIGO)
            ->setName('Aviso interno técnico')
            // `staff`: no cuelga de ninguna reserva. Es el mismo contexto que el aviso de
            // escalado, que es su hermano.
            ->setContextType('staff')
            ->setAutoenvioHabilitada(false)
            ->setWhatsappMetaTmpl([
                'is_active' => true,
                'category' => 'UTILITY',
                // Con sufijo desde el primer día: el nombre de una plantilla de Meta no se puede
                // editar, así que el día que haya que reescribir el cuerpo hará falta un `_v2`.
                // Ver docs/Mensajeria.md §18.
                'meta_template_name' => self::CODIGO . '_v1',
                // Se pone en `true` cuando Meta la apruebe, no antes: es lo que mira el envío
                // para saber si puede usarla fuera de la ventana.
                'is_official_meta' => false,
                'header' => [['format' => 'TEXT', 'language' => 'es', 'content' => 'Aviso técnico']],
                'footer' => [['language' => 'es', 'content' => 'Aviso automático del PMS']],
                'body' => $cuerpo,
                // Sin botones a propósito: el de escalado lleva uno al chat del huésped porque
                // ahí hay un chat al que ir. Aquí la acción es mirar el panel o el log, que no
                // es una URL sola, y cada botón es una cosa más que Meta puede rechazar.
                'buttons_map' => [],
            ])
            // Dentro de la ventana de 24 h se manda el texto libre que redacta quien avisa, que
            // puede ser multilínea y traer todo el contexto. Esto es sólo el respaldo.
            ->setWhatsappLinkTmpl(['is_active' => false, 'body' => []])
            ->setBeds24Tmpl(['is_active' => false, 'body' => []])
            ->setEmailTmpl(['is_active' => false, 'subject' => [], 'body' => []]);

        $this->em->persist($plantilla);
        $this->em->flush();

        $io->success(sprintf('«%s» creada.', self::CODIGO));
        $io->note(sprintf(
            'Falta subirla y que Meta la apruebe: php bin/console msg:meta:push %s --idiomas=es. '
            . 'Sólo español: la lee el equipo. Cuando esté APPROVED, poner is_official_meta a true.',
            self::CODIGO
        ));

        return Command::SUCCESS;
    }
}
