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
 * ── Los siete idiomas, como todas ───────────────────────────────────────────
 * Se escribe en español y AutoTranslate rellena el resto al guardar; a Meta se sube entera, por
 * el mismo camino que cualquier otra. Tuvo su tentación —la lee el equipo, que habla español—
 * pero una plantilla que se mantiene distinta a las demás es una excepción que hay que recordar
 * cada vez que alguien la toque, y se olvida justo el día que importa.
 *
 * Nace `hidden` porque es de una vez. Idempotente por el código.
 *
 *   php bin/console msg:crear:aviso-tecnico --dry-run
 *   php bin/console msg:crear:aviso-tecnico
 *   php bin/console msg:meta:push aviso_tecnico_interno --todos
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
     * El nombre en Meta, que va por su segunda generación antes de estrenarse.
     *
     * La `_v1` se subió con el pie traducido a máquina y el francés decía «syndrome
     * prémenstruel». Meta **no deja editar una plantilla pendiente** y borrarla reserva el par
     * nombre+idioma cuatro semanas, así que la salida limpia es la rotación de siempre: se
     * abandona la `_v1` —que no usa nadie— y se crea la `_v2` con el texto bueno.
     */
    private const string NOMBRE_META = self::CODIGO . '_v2';

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

    /**
     * El pie, a mano en los siete idiomas, con el nombre propio del sistema.
     *
     * 🔥 **«PMS» no se vuelve a escribir en una plantilla.** La primera versión decía «Aviso
     * automático del PMS» y se dejó traducir. Una sigla no lleva contexto que ayude a acertar,
     * así que el traductor eligió la acepción famosa:
     *
     * ```
     * fr  Notification automatique du syndrome prémenstruel
     * it  Notifica automatica del servizio di pianificazione familiare (PMS).
     * ```
     *
     * Y hay un segundo motivo, mejor que el primero: **esto ya no es sólo un PMS**. En `src/`
     * conviven veinte módulos —`Cotizacion`, `Travel`, `Operacion`, `Finanzas`, `Domotica`,
     * `Agent`…— y el alojamiento es uno de ellos. Decisión de Jorge (20/09/2026): en plantillas
     * se llama **«Sistema OpenPeru»**, que además es nombre propio y por eso no se traduce.
     *
     * El resto del pie sí va en cada idioma, escrito aquí y no a máquina: el mecanismo de
     * traducción sigue siendo el de siempre, lo que cambia es que se le da la fuente correcta.
     *
     * @var list<array{language: string, content: string}>
     */
    private const array PIE = [
        ['language' => 'es', 'content' => 'Aviso automático · Sistema OpenPeru'],
        ['language' => 'en', 'content' => 'Automatic alert · Sistema OpenPeru'],
        ['language' => 'pt', 'content' => 'Alerta automático · Sistema OpenPeru'],
        ['language' => 'fr', 'content' => 'Alerte automatique · Sistema OpenPeru'],
        ['language' => 'it', 'content' => 'Avviso automatico · Sistema OpenPeru'],
        ['language' => 'de', 'content' => 'Automatische Meldung · Sistema OpenPeru'],
        ['language' => 'nl', 'content' => 'Automatische melding · Sistema OpenPeru'],
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
        $simular = (bool) $input->getOption('dry-run');

        $existente = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => self::CODIGO]);

        if ($existente !== null) {
            // Idempotente por CONTENIDO, no por existencia: la primera pasada creó la plantilla
            // con el pie traducido a máquina —y con «PMS» convertido en síndrome premenstruel—,
            // así que encontrarla no puede significar dejarla como está.
            $meta = $existente->getWhatsappMetaTmpl() ?? [];
            $nombreActual = (string) ($meta['meta_template_name'] ?? '');

            if (($meta['footer'] ?? null) === self::PIE && $nombreActual === self::NOMBRE_META) {
                $io->success(sprintf('«%s» ya está como debe.', self::CODIGO));

                return Command::SUCCESS;
            }

            $io->section('Se pone al día');
            $io->writeln('<fg=red>- ' . json_encode($meta['footer'] ?? [], JSON_UNESCAPED_UNICODE) . '</>');
            $io->writeln('<fg=green>+ los siete a mano, con «Sistema OpenPeru»</>');

            if ($nombreActual !== self::NOMBRE_META) {
                $io->writeln(sprintf('<fg=green>~ nombre en Meta: %s → %s</>', $nombreActual, self::NOMBRE_META));
            }

            if ($simular) {
                $io->note('Simulación: no se ha escrito nada.');

                return Command::SUCCESS;
            }

            // El estado por idioma se va con la generación vieja: los `PENDING` que hay guardados
            // son de la `_v1`, que se abandona. Dejarlos diría que la `_v2` ya está en revisión.
            $cuerpo = array_map(
                static fn (array $fila): array => [
                    'language' => (string) ($fila['language'] ?? 'es'),
                    'content' => (string) ($fila['content'] ?? ''),
                ],
                $meta['body'] ?? []
            );

            $existente->setWhatsappMetaTmpl([
                'footer' => self::PIE,
                'meta_template_name' => self::NOMBRE_META,
                'body' => $cuerpo,
            ] + $meta);
            $this->em->flush();

            $io->success('Al día. Hay que subirla a Meta: php bin/console msg:meta:push ' . self::CODIGO . ' --todos');

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
                'meta_template_name' => self::NOMBRE_META,
                // Se pone en `true` cuando Meta la apruebe, no antes: es lo que mira el envío
                // para saber si puede usarla fuera de la ventana.
                'is_official_meta' => false,
                'header' => [['format' => 'TEXT', 'language' => 'es', 'content' => 'Aviso técnico']],
                'footer' => self::PIE,
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
            'Falta subirla y que Meta la apruebe: php bin/console msg:meta:push %s --todos. '
            . 'Los siete idiomas, como cualquier otra: el cuerpo lo traduce AutoTranslate al '
            . 'guardar y se sube por el mismo camino. Cuando esté APPROVED, is_official_meta a '
            . 'true.',
            self::CODIGO
        ));

        return Command::SUCCESS;
    }
}
