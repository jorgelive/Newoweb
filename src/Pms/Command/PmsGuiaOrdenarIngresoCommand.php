<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsGuiaSeccionHasItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La sección «Ingreso» de todas las casitas, en el orden de la casa 2.
 *
 * ── Por qué ese orden ───────────────────────────────────────────────────────
 * Los tres primeros pasos son los que alguien lee **con la maleta en la mano y de noche**:
 *
 *   1. Ubicación — dónde está
 *   2. Llaves — cómo abre la caja fuerte
 *   3. Puerta — cuál es su puerta
 *
 * Las demás casitas tenían **Traslados y Estacionamiento en medio**, entre la dirección y las
 * llaves: dos temas de antes del viaje interrumpiendo justo la secuencia de entrar. Se van al
 * final, donde siguen estando para quien los busque.
 *
 * Lo ordenó Jorge a mano en la casa 2 y esto lo copia al resto: no es una preferencia de estilo,
 * es que el orden de una lista es lo único que decide qué se lee primero.
 *
 * ── Qué hace con lo que no conoce ───────────────────────────────────────────
 * Los ítems que no estén en la receta **no se descartan**: se quedan detrás, en su orden actual.
 * Así, un ítem nuevo que alguien añada a una sección aparece al final en vez de desaparecer — y
 * desaparecer es lo que no se nota.
 *
 * No toca textos, así que no hay traducción de por medio: sólo el campo `orden` de la relación.
 * Es idempotente y sirve igual el día que se cree una casita nueva.
 */
#[AsCommand(
    name: 'app:pms:guia:ordenar-ingreso',
    description: 'Pone la sección «Ingreso» de cada casita en el orden de la casa 2. Idempotente.',
)]
final class PmsGuiaOrdenarIngresoCommand extends Command
{
    /**
     * El orden bueno, por nombre interno del ítem.
     *
     * `Puerta` lleva el número de su casita, así que se compara por prefijo: es el único que
     * cambia de nombre en cada sección.
     *
     * @var list<string>
     */
    private const array ORDEN = [
        'Ubicación (general)',
        'Llaves (general)',
        'Puerta (casa ',
        'Early check in Late Check Out',
        'Despues de ingresar (general)',
        'Perdida llaves (general)',
        'Traslados (general)',
        'Estacionamiento (general)',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $filas = [];
        $tocadas = 0;

        /** @var list<PmsGuiaSeccion> $secciones */
        $secciones = $this->em->getRepository(PmsGuiaSeccion::class)->findAll();

        foreach ($secciones as $seccion) {
            $nombre = (string) $seccion->getNombreInterno();

            if (!str_starts_with($nombre, 'Ingreso (casa ')) {
                continue;
            }

            /** @var list<PmsGuiaSeccionHasItem> $relaciones */
            $relaciones = $this->em->getRepository(PmsGuiaSeccionHasItem::class)->findBy(['seccion' => $seccion]);

            usort($relaciones, static fn (PmsGuiaSeccionHasItem $a, PmsGuiaSeccionHasItem $b): int => $a->getOrden() <=> $b->getOrden());

            $ordenadas = $this->enOrden($relaciones);
            $antes = array_map(static fn (PmsGuiaSeccionHasItem $r): string => (string) $r->getItem()?->getNombreInterno(), $relaciones);
            $despues = array_map(static fn (PmsGuiaSeccionHasItem $r): string => (string) $r->getItem()?->getNombreInterno(), $ordenadas);

            if ($antes === $despues) {
                $filas[] = [$nombre, '<comment>ya estaba</comment>'];
                continue;
            }

            ++$tocadas;
            $filas[] = [$nombre, implode(' → ', array_map($this->corto(...), $despues))];

            if ($simular) {
                continue;
            }

            foreach ($ordenadas as $posicion => $relacion) {
                $relacion->setOrden($posicion);
            }
        }

        if ($filas === []) {
            $io->error('No hay ninguna sección «Ingreso (casa …)».');

            return Command::FAILURE;
        }

        $io->table(['Sección', 'Orden'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        if ($tocadas === 0) {
            $io->success('Todas estaban en orden.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d secciones reordenadas.', $tocadas));

        return Command::SUCCESS;
    }

    /**
     * Las relaciones en el orden bueno; lo que no esté en la receta va detrás, como estaba.
     *
     * @param list<PmsGuiaSeccionHasItem> $relaciones Ya ordenadas por su `orden` actual.
     *
     * @return list<PmsGuiaSeccionHasItem>
     */
    private function enOrden(array $relaciones): array
    {
        $ordenadas = [];
        $restantes = $relaciones;

        foreach (self::ORDEN as $buscado) {
            foreach ($restantes as $i => $relacion) {
                $nombre = (string) $relacion->getItem()?->getNombreInterno();

                if ($nombre === $buscado || str_starts_with($nombre, $buscado)) {
                    $ordenadas[] = $relacion;
                    unset($restantes[$i]);
                    break;
                }
            }
        }

        return [...$ordenadas, ...array_values($restantes)];
    }

    /** «Puerta (casa 4)» → «Puerta»: la tabla tiene que caber en una línea. */
    private function corto(string $nombre): string
    {
        return trim((string) preg_replace('/\s*\((general|casa \d+)\)$/', '', $nombre));
    }
}
