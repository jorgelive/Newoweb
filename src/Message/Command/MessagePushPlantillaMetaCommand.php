<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Entity\MessageTemplate;
use App\Message\Service\Meta\Template\WhatsappMetaTemplatePushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Sube a Meta la estructura de UNA plantilla, por idiomas elegidos.
 *
 * ## Por qué existe, si el panel ya tiene el botón
 *
 * Porque el plan de reformulación (§18.b de `docs/Mensajeria.md`) manda las plantillas de Meta
 * **en una sola tanda**: cada reescritura de un cuerpo aprobado es una rotación completa
 * —crear `x_v2`, repuntar, borrar— con el bloqueo de cuatro semanas detrás. Ocho plantillas por
 * siete idiomas son cincuenta y seis subidas, y hacerlas a mano por el panel es donde se cuela
 * el idioma que no tocaba.
 *
 * No duplica ni una regla: llama al mismo {@see WhatsappMetaTemplatePushService} que el botón.
 *
 * ## El idioma es obligatorio, y no es una molestia
 *
 * Subir un idioma **reabre su revisión en Meta**. Reenviar los siete porque uno fue rechazado
 * devuelve a `PENDING` seis que ya estaban aprobadas y funcionando, y hasta que Meta las vuelva
 * a mirar **no se pueden usar fuera de la ventana de 24 h**. Por eso no hay un modo «todos» por
 * omisión: o se nombran los idiomas, o se pide `--todos` a sabiendas.
 *
 * ```
 * bin/console msg:meta:push pago --idiomas=es,en
 * bin/console msg:meta:push pago --todos
 * bin/console msg:meta:push pago --ver          # no sube nada: enseña qué hay y en qué estado
 * ```
 */
#[AsCommand(
    name: 'msg:meta:push',
    description: 'Sube a Meta la estructura de una plantilla, por idiomas.'
)]
final class MessagePushPlantillaMetaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WhatsappMetaTemplatePushService $push,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Código de la plantilla («pago», «check_out»…).')
            ->addOption('idiomas', null, InputOption::VALUE_REQUIRED, 'Códigos separados por coma: es,en,pt')
            ->addOption('todos', null, InputOption::VALUE_NONE, 'Sube TODOS los idiomas que tenga la plantilla.')
            ->addOption('ver', null, InputOption::VALUE_NONE, 'No sube nada: enseña los idiomas y su estado.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getArgument('code');

        $plantilla = $this->em->getRepository(MessageTemplate::class)->findOneBy(['code' => $code]);

        if (!$plantilla instanceof MessageTemplate) {
            $io->error(sprintf('No existe ninguna plantilla con el código «%s».', $code));

            return Command::FAILURE;
        }

        $metaTmpl = $plantilla->getWhatsappMetaTmpl() ?? [];
        $cuerpos = is_array($metaTmpl['body'] ?? null) ? $metaTmpl['body'] : [];

        if ($cuerpos === []) {
            $io->error(sprintf('«%s» no tiene ni un cuerpo en el bloque de Meta: no hay nada que subir.', $code));

            return Command::FAILURE;
        }

        $io->title(sprintf('%s → Meta (%s)', $code, (string) ($metaTmpl['meta_template_name'] ?? '¡sin nombre en Meta!')));

        $io->table(
            ['Idioma', 'Estado', 'Caracteres'],
            array_map(static fn (array $b): array => [
                strtoupper((string) ($b['language'] ?? '?')),
                (string) ($b['status'] ?? 'SIN ENVIAR'),
                (string) mb_strlen((string) ($b['content'] ?? '')),
            ], $cuerpos)
        );

        if ($input->getOption('ver')) {
            return Command::SUCCESS;
        }

        $idiomas = $this->idiomasPedidos($input);

        if ($idiomas === null) {
            $io->error(
                'Di qué idiomas subir: --idiomas=es,en o --todos. Subir un idioma reabre su '
                . 'revisión en Meta, así que no hay un «todos» por omisión.'
            );

            return Command::FAILURE;
        }

        $io->warning(sprintf(
            'Se van a enviar a revisión: %s. Los que ya estén APPROVED vuelven a PENDING y '
            . 'no se podrán usar fuera de la ventana de 24 h hasta que Meta los mire otra vez.',
            $idiomas === [] ? 'TODOS' : implode(', ', array_map('strtoupper', $idiomas))
        ));

        if ($input->isInteractive() && !$io->confirm('¿Subimos?', false)) {
            $io->text('No se subió nada.');

            return Command::SUCCESS;
        }

        try {
            $resultados = $this->push->pushTemplateToMeta($plantilla, $idiomas);
        } catch (Throwable $e) {
            $io->error('No se subió nada: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $fallos = 0;

        foreach ($resultados as $lang => $r) {
            $ok = ($r['status'] ?? '') === 'success';
            $fallos += $ok ? 0 : 1;

            $io->writeln(sprintf(
                ' %s %s  %s',
                $ok ? '<info>✔</info>' : '<error>✘</error>',
                str_pad(strtoupper((string) $lang), 4),
                $ok
                    ? sprintf('%s (%s)', (string) ($r['action'] ?? '?'), (string) ($r['meta_id'] ?? 'sin id'))
                    : (string) ($r['message'] ?? '')
            ));
        }

        if ($fallos > 0) {
            $io->error(sprintf('%d idioma(s) no se subieron.', $fallos));

            return Command::FAILURE;
        }

        $io->success(
            'Enviados a revisión. El estado real lo trae `app:whatsapp:sync-templates` '
            . '(que ya corre a las 03:15); hasta que Meta apruebe, «Oficial en Meta» sigue en no.'
        );

        return Command::SUCCESS;
    }

    /**
     * @return list<string>|null Lista de idiomas, `[]` para todos, `null` si no se eligió nada.
     */
    private function idiomasPedidos(InputInterface $input): ?array
    {
        if ($input->getOption('todos')) {
            return [];
        }

        $crudo = $input->getOption('idiomas');

        if (!is_string($crudo) || trim($crudo) === '') {
            return null;
        }

        $idiomas = array_values(array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', $crudo)
        ), static fn (string $l): bool => $l !== ''));

        return $idiomas === [] ? null : $idiomas;
    }
}
