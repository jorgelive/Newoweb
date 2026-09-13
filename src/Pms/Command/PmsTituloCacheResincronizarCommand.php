<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone al día el título cacheado de los eventos cuyo huésped cambió de nombre.
 *
 * 🔥 **El arrastre lo hace ya `PmsEventoCalendarioCacheNormalizerListener`**, pero sólo para lo
 * que pase a partir de ahora. Esto es el backfill de lo que se quedó atrás mientras el caché no
 * tenía invalidación: 23 de 423 eventos el 12/09/2026, todos ellos reservas que el corrector de
 * orden y caja del nombre había arreglado — el caché guardaba la versión cruzada o en minúsculas.
 *
 * ⚠️ **Un título que no coincide con NINGUNA de las dos formas no se toca.** Se compara contra el
 * nombre actual y contra el mismo par cambiado de sitio, que son las dos cosas que este sistema ha
 * podido escribir ahí. Cualquier otra cadena la puso una persona, y pisarla sería borrar trabajo
 * de alguien para arreglar un caché.
 */
#[AsCommand(
    name: 'pms:titulo-cache:resincronizar',
    description: 'Pone al día el título cacheado del calendario cuando el nombre del huésped cambió después.',
)]
final class PmsTituloCacheResincronizarCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('aplicar', null, InputOption::VALUE_NONE, 'Escribe. Sin esto sólo enseña.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');

        /** @var list<PmsEventoCalendario> $eventos */
        $eventos = $this->em->createQuery(
            'SELECT e FROM ' . PmsEventoCalendario::class . ' e JOIN e.reserva r WHERE e.tituloCache IS NOT NULL'
        )->getResult();

        $filas = [];
        $tocados = 0;

        foreach ($eventos as $evento) {
            $reserva = $evento->getReserva();
            if ($reserva === null) {
                continue;
            }

            $nombre = trim((string) $reserva->getNombreCliente());
            $apellido = trim((string) $reserva->getApellidoCliente());
            $cache = trim((string) $evento->getTituloCache());

            $bueno = trim($nombre . ' ' . $apellido);
            $cruzado = trim($apellido . ' ' . $nombre);

            if ($bueno === '' || $cache === $bueno) {
                continue;
            }

            // Las dos formas que este sistema pudo escribir. Si no es ninguna, es de una persona.
            if ($cache !== $cruzado && mb_strtolower($cache) !== mb_strtolower($bueno)) {
                continue;
            }

            $filas[] = [$cache, $bueno];
            ++$tocados;

            if ($aplicar) {
                $evento->setTituloCache(mb_substr($bueno, 0, 180));
            }
        }

        if ($aplicar && $tocados > 0) {
            $this->em->flush();
        }

        if ($filas !== []) {
            $io->table(['Decía', 'Dice ahora'], $filas);
        }

        $io->success(sprintf(
            '%d de %d eventos %s.',
            $tocados,
            count($eventos),
            $aplicar ? 'actualizados' : 'se actualizarían (usa --aplicar)',
        ));

        return Command::SUCCESS;
    }
}
