<?php

declare(strict_types=1);

namespace App\Operacion\Command;

use App\Operacion\Entity\OperacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Devuelve a `null` las horas de recojo que en realidad nadie pactó.
 *
 * ── De dónde salen ──────────────────────────────────────────────────────────
 * De la migración `Version20260817220000`. Hasta ese día el campo se llamaba `hora_recojo_real` y
 * era **las dos cosas a la vez**: la hora vendida y la de recojo. Al partirlo, la migración copió
 * su valor a `hora_componente` —correcto, de ahí salía— pero dejó el original intacto en lo que
 * después pasó a llamarse `hora_recojo`.
 *
 * Resultado: 17 filas con las dos horas idénticas y **ninguna con horas distintas**. Cero en
 * diecisiete no es casualidad: nadie ha pactado nunca una hora de recojo diferente.
 *
 * ── Por qué importa que mienta ──────────────────────────────────────────────
 * El campo significa «el operador fijó esta hora», y de ahí cuelgan cosas:
 *
 *  - La fila muestra «vend. HH:MM» sólo cuando difieren — con la copia puesta, nunca.
 *  - **Al emitir, `horaRecojoConfirmada` se rellena con ésta.** Y el docblock del ítem dice que
 *    nula significa «el proveedor todavía no confirmó» y con valor «ya confirmó»: esas 17 salían
 *    a la orden como si el proveedor hubiera confirmado una hora que nadie le preguntó.
 *
 * ⚠️ **Sólo se limpia lo IDÉNTICO.** Si alguien pactó una hora distinta, se queda. Y si pactó una
 * que casualmente coincidía con la vendida, esto se la lleva — es la única pérdida posible, y se
 * asume a sabiendas: el dato ya era indistinguible de la copia, así que no había forma de
 * conservarlo sin conservar también las dieciséis mentiras.
 *
 * La fila **no cambia de aspecto**: sigue enseñando la misma hora, porque ya cae al `fallback` de
 * `horaComponente`. Lo único que cambia es que deja de afirmar algo que no ocurrió.
 */
#[AsCommand(
    name: 'app:operacion:limpiar-hora-recojo-copiada',
    description: 'Pone a null las horas de recojo idénticas a la vendida, que copió una migración.',
)]
final class OperacionLimpiarHoraRecojoCopiadaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe nada: sólo enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var list<OperacionServicio> $todos */
        $todos = $this->em->getRepository(OperacionServicio::class)->findAll();
        $copiadas = [];
        $pactadas = 0;

        foreach ($todos as $servicio) {
            $recojo = $servicio->getHoraRecojo();

            if ($recojo === null) {
                continue;
            }

            if ($recojo !== $servicio->getHoraComponente()) {
                ++$pactadas;
                continue;
            }

            $copiadas[] = $servicio;
        }

        if ($copiadas === []) {
            $io->success(sprintf('Nada que limpiar. %d horas de recojo pactadas de verdad, intactas.', $pactadas));

            return Command::SUCCESS;
        }

        $io->section(sprintf('%d horas de recojo que son copia de la vendida', count($copiadas)));

        foreach ($copiadas as $servicio) {
            $io->writeln(sprintf(
                '  <info>·</info> %-10s %-6s %-34s %s',
                $servicio->getFechaServicio()?->format('d/m/Y') ?? '—',
                (string) $servicio->getHoraRecojo(),
                mb_substr($servicio->getDescripcionServicio(), 0, 33),
                mb_substr((string) $servicio->getFile()?->getNombreGrupo(), 0, 24)
            ));

            $servicio->setHoraRecojo(null);
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d %s · %d pactadas de verdad, sin tocar. La fila sigue mostrando la misma hora.',
            count($copiadas),
            $seco ? 'se limpiarían' : 'limpiadas',
            $pactadas
        ));

        return Command::SUCCESS;
    }
}
