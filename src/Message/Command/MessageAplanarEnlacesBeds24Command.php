<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Message\Service\Exchange\Tasks\Beds24Receive\EnlaceDeBeds24;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aplana a texto las anclas HTML que Beds24 dejó dentro de mensajes ya guardados.
 *
 * El panel escapa el HTML por seguridad, así que un `<a href="…">adjunto</a>` se pintaba crudo
 * en la burbuja. {@see EnlaceDeBeds24} lo arregla para los que entran; esto es el arrastre.
 *
 * ⚠️ **Escribe por DBAL y no por el ORM, a propósito.** `Message` tiene listeners de `preUpdate`
 * y `postUpdate` —traductor, auto-respondedor, Mercure y el ENCOLADOR DE ENVÍO—: pasar mensajes
 * históricos por el ORM podría despertar el encolador y **re-enviárselos a los huéspedes**. Aquí
 * no hay nada que ningún listener deba recalcular.
 *
 * No va como migración porque la transformación no es un `REPLACE` de SQL: el texto del ancla y
 * la URL cambian en cada fila, y reescribir a mano en SQL lo que ya está probado en PHP es
 * duplicar la regla. Idempotente: una vez aplanado no queda ningún `<a`.
 */
#[AsCommand(
    name: 'app:message:aplanar-enlaces-beds24',
    description: 'Convierte a texto plano las anclas HTML de Beds24 en los mensajes ya guardados.'
)]
final class MessageAplanarEnlacesBeds24Command extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que cambiaría sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulacro = (bool) $input->getOption('dry-run');

        $filas = $this->db->fetchAllAssociative(
            'SELECT id, content_external, content_local FROM msg_message '
            . "WHERE content_external LIKE '%<a %href=%' OR content_local LIKE '%<a %href=%'"
        );

        if ($filas === []) {
            $io->success('No queda ninguna ancla de Beds24 sin aplanar.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d mensaje(s) con anclas HTML%s', count($filas), $simulacro ? ' — SIMULACRO' : ''));

        $tocados = 0;
        $muestra = [];

        foreach ($filas as $fila) {
            $cambios = [];

            foreach (['content_external', 'content_local'] as $columna) {
                $antes = $fila[$columna];

                if (!is_string($antes) || $antes === '') {
                    continue;
                }

                $despues = EnlaceDeBeds24::normalizar($antes);

                if ($despues !== $antes) {
                    $cambios[$columna] = $despues;
                }
            }

            if ($cambios === []) {
                continue;
            }

            ++$tocados;

            if (count($muestra) < 3) {
                $muestra[] = [mb_substr((string) $fila['content_external'], 0, 58), mb_substr(reset($cambios), 0, 58)];
            }

            if (!$simulacro) {
                $this->db->update('msg_message', $cambios, ['id' => $fila['id']]);
            }
        }

        if ($muestra !== []) {
            $io->table(['antes', 'después'], $muestra);
        }

        $simulacro
            ? $io->warning(sprintf('%d mensaje(s) se aplanarían. Nada escrito.', $tocados))
            : $io->success(sprintf('%d mensaje(s) aplanados.', $tocados));

        return Command::SUCCESS;
    }
}
