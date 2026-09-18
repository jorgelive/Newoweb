<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
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
 * Dos fichas cambian de sección, porque no eran de donde estaban.
 *
 * ── «Pérdida de llaves»: de Ingreso a Pagos y Reglamento ────────────────────
 * No es un paso de entrar: es una norma con coste —«el cerrajero corre por cuenta del huésped»—.
 * Estaba entre «Después de ingresar» y «Traslados», o sea que aparecía mientras alguien todavía
 * está entrando, y lo único que aportaba ahí era una advertencia de dinero. Es la regla de la casa
 * escrita en `CLAUDE.md`: **lo que niega, limita, advierte o cuesta dinero baja un peldaño**.
 *
 * Va detrás de «Reglas», que es donde se busca el día que pasa.
 *
 * ── «Horario solicitudes»: de Pagos y Reglamento a Servicios ────────────────
 * El mismo error al revés: no habla ni de pagos ni de reglas. Son los horarios de atención y cómo
 * pedir papel higiénico o jabón. Va **primero** en Servicios porque enmarca el resto: dice a qué
 * hora se atiende y con cuánta antelación hay que avisar, antes de la lista de lo que se puede pedir.
 *
 * ── Qué hace y qué no ───────────────────────────────────────────────────────
 * Mueve la RELACIÓN sección↔ítem, no el contenido: los textos, sus siete idiomas y sus marcadores
 * quedan intactos y no pasa nada por `AutoTranslate`. Renumera las dos secciones afectadas para que
 * no queden huecos.
 *
 * Idempotente: si ya está en su sitio, no toca nada. Nace `hidden` porque es de una vez.
 */
#[AsCommand(
    name: 'app:pms:guia:mover-items',
    description: 'Pérdida de llaves va a Reglamento; Horario de solicitudes, a Servicios. Idempotente.',
    hidden: true,
)]
final class PmsGuiaMoverItemsCommand extends Command
{
    /**
     * Qué se mueve, de dónde, a dónde y detrás de qué.
     *
     * `desde` con `*` significa «de todas las secciones cuyo nombre empiece así»: la de ingreso es
     * una por casita.
     *
     * @var list<array{item: string, desde: string, hasta: string, detrasDe: ?string}>
     */
    private const array MUDANZAS = [
        [
            'item' => 'Perdida llaves (general)',
            'desde' => 'Ingreso (casa *',
            'hasta' => 'Pagos y Reglamento (general)',
            'detrasDe' => 'Reglas (general)',
        ],
        [
            'item' => 'Horario solicitudes (general)',
            'desde' => 'Pagos y Reglamento (general)',
            'hasta' => 'Servicios (general)',
            // Primero: enmarca el resto —a qué hora se atiende y con cuánta antelación avisar—.
            'detrasDe' => null,
        ],
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
        $cambios = 0;

        foreach (self::MUDANZAS as $mudanza) {
            $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => $mudanza['item']]);

            if (!$item instanceof PmsGuiaItem) {
                $io->error(sprintf('No existe la ficha «%s». No se toca nada.', $mudanza['item']));

                return Command::FAILURE;
            }

            $destino = $this->em->getRepository(PmsGuiaSeccion::class)->findOneBy(['nombreInterno' => $mudanza['hasta']]);

            if (!$destino instanceof PmsGuiaSeccion) {
                $io->error(sprintf('No existe la sección «%s». No se toca nada.', $mudanza['hasta']));

                return Command::FAILURE;
            }

            $quitadas = 0;

            /** @var list<PmsGuiaSeccionHasItem> $relaciones */
            $relaciones = $this->em->getRepository(PmsGuiaSeccionHasItem::class)->findBy(['item' => $item]);

            foreach ($relaciones as $relacion) {
                $nombre = (string) $relacion->getSeccion()?->getNombreInterno();

                if (!$this->casa($nombre, $mudanza['desde'])) {
                    continue;
                }

                ++$quitadas;

                if (!$simular) {
                    $this->em->remove($relacion);
                }
            }

            $yaEstaba = $this->relacionEn($destino, $item) !== null;

            if ($quitadas === 0 && $yaEstaba) {
                $filas[] = [$mudanza['item'], '<comment>ya estaba en ' . $mudanza['hasta'] . '</comment>'];
                continue;
            }

            ++$cambios;
            $filas[] = [
                $mudanza['item'],
                sprintf(
                    'sale de %s (%d) → entra en %s%s',
                    $mudanza['desde'],
                    $quitadas,
                    $mudanza['hasta'],
                    $mudanza['detrasDe'] === null ? ', el primero' : ', detrás de ' . $mudanza['detrasDe']
                ),
            ];

            if ($simular) {
                continue;
            }

            if (!$yaEstaba) {
                $nueva = (new PmsGuiaSeccionHasItem())->setSeccion($destino)->setItem($item);
                $this->em->persist($nueva);
            }

            $this->em->flush();
            $this->renumerar($destino, $item, $mudanza['detrasDe']);
        }

        $io->table(['Ficha', 'Qué pasa'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        if ($cambios === 0) {
            $io->success('Cada ficha ya está en su sección.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Sólo cambió la sección: los textos y sus siete idiomas siguen igual.');

        return Command::SUCCESS;
    }

    /** ¿Ese nombre de sección casa con el patrón de origen? Con `*` al final, por prefijo. */
    private function casa(string $nombre, string $patron): bool
    {
        return str_ends_with($patron, '*')
            ? str_starts_with($nombre, rtrim($patron, '*'))
            : $nombre === $patron;
    }

    private function relacionEn(PmsGuiaSeccion $seccion, PmsGuiaItem $item): ?PmsGuiaSeccionHasItem
    {
        return $this->em->getRepository(PmsGuiaSeccionHasItem::class)->findOneBy(['seccion' => $seccion, 'item' => $item]);
    }

    /**
     * Deja la sección sin huecos y con el recién llegado en su sitio.
     *
     * Se renumera entera y no sólo el nuevo: los `orden` traían saltos de ediciones anteriores, y
     * un empate decide el orden por el azar del `id`.
     */
    private function renumerar(PmsGuiaSeccion $seccion, PmsGuiaItem $recienLlegado, ?string $detrasDe): void
    {
        /** @var list<PmsGuiaSeccionHasItem> $relaciones */
        $relaciones = $this->em->getRepository(PmsGuiaSeccionHasItem::class)->findBy(['seccion' => $seccion]);

        usort($relaciones, static fn (PmsGuiaSeccionHasItem $a, PmsGuiaSeccionHasItem $b): int => $a->getOrden() <=> $b->getOrden());

        $sinElNuevo = array_values(array_filter(
            $relaciones,
            static fn (PmsGuiaSeccionHasItem $r): bool => $r->getItem() !== $recienLlegado
        ));

        $nuevo = $this->relacionEn($seccion, $recienLlegado);

        if ($nuevo === null) {
            return;
        }

        $posicion = 0;

        if ($detrasDe !== null) {
            foreach ($sinElNuevo as $i => $relacion) {
                if ((string) $relacion->getItem()?->getNombreInterno() === $detrasDe) {
                    $posicion = $i + 1;
                    break;
                }
            }
        }

        $final = $sinElNuevo;
        array_splice($final, $posicion, 0, [$nuevo]);

        foreach ($final as $i => $relacion) {
            $relacion->setOrden($i);
        }

        $this->em->flush();
    }
}
