<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEstablecimientoMedia;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Enum\PmsEstablecimientoMediaTipo;
use App\Service\Translate\AutoTranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Saca el enlace del mapa de las fichas de la guía y lo deja en el ESTABLECIMIENTO (23/10/2026).
 *
 * La ficha «Ubicación (general)» llevaba `{{ map: https://www.google.com/maps/place/… }}` escrito
 * dentro, en los siete idiomas de `descripcion` y en el texto del agente: ocho copias del mismo
 * punto. Pasa a ser un medio del establecimiento (`PmsEstablecimientoMediaTipo::MAPA`) y la ficha
 * dice `{{ mapa }}`, como ya hacen los croquis y los vídeos.
 *
 * ── Sin retraducir nada ─────────────────────────────────────────────────────
 * Se sustituye en LOS SIETE idiomas, y después se SELLA (`AutoTranslationService::processEntity(…,
 * soloSellar: true)`): las traducciones quedan marcadas como hechas desde el español nuevo, y el
 * listener no las rehace. Cambiar un marcador no es cambiar el texto, y retraducir seis idiomas por
 * eso podía mover la redacción que ya estaba bien. Por comando y no por SQL, por `#[AutoTranslate]`.
 *
 * ⚠️ **Hay que QUITAR la huella vieja antes de sellar.** Sellar sólo marca las filas SIN
 * `origenHash`; las que lo tenían se quedaban con el del español de antes y el siguiente guardado
 * de la ficha retraducía los seis idiomas. Pasó en la primera ejecución (23/10/2026), y `--resellar`
 * es lo que lo arregló: vuelve a sellar las fichas que ya dicen `{{ mapa }}`.
 *
 * Idempotente: sin `{{ map: … }}` que mover no escribe nada. Si dos fichas o dos idiomas apuntan a
 * sitios distintos, se para y lo dice: elegir cuál vale no le toca a un comando.
 */
#[AsCommand(
    name: 'app:pms:guia:mapa-al-establecimiento',
    description: 'Mueve el enlace del mapa de la guía al establecimiento y deja {{ mapa }}. Idempotente.',
    hidden: true,
)]
final class PmsGuiaMapaAlEstablecimientoCommand extends Command
{
    private const string MARCADOR = '/\{\{\s*map\s*:\s*(https?:\/\/[^\s}]+)\s*\}\}/i';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AutoTranslationService $traductor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
        $this->addOption('resellar', null, InputOption::VALUE_NONE, 'Vuelve a sellar las fichas que ya dicen {{ mapa }} (ver la cabecera)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        if ($input->getOption('resellar') === true) {
            return $this->resellar($io, $simular);
        }

        $establecimientos = $this->em->getRepository(PmsEstablecimiento::class)->findAll();
        if (\count($establecimientos) !== 1) {
            // La guía no cuelga de un establecimiento: con uno solo no hay duda de cuál es el suyo.
            $io->error(sprintf('Hay %d establecimientos: no sé a cuál llevar el mapa.', \count($establecimientos)));

            return Command::FAILURE;
        }
        $establecimiento = $establecimientos[0];

        $urls = [];
        $fichas = [];

        foreach ($this->em->getRepository(PmsGuiaItem::class)->findAll() as $item) {
            $textos = array_map(static fn (array $fila): string => (string) ($fila['content'] ?? ''), $item->getDescripcion() ?? []);
            $textos[] = (string) $item->getAgenteContenido();

            $enEsta = [];
            foreach ($textos as $texto) {
                if (preg_match_all(self::MARCADOR, $texto, $m) > 0) {
                    $enEsta = [...$enEsta, ...$m[1]];
                }
            }

            if ($enEsta !== []) {
                $fichas[] = $item;
                $urls = [...$urls, ...$enEsta];
            }
        }

        $urls = array_values(array_unique($urls));

        if ($fichas === []) {
            $io->success('No queda ningún {{ map: … }} en la guía.');

            return Command::SUCCESS;
        }

        if (\count($urls) !== 1) {
            $io->error("Las fichas apuntan a sitios distintos; corrígelo a mano antes:\n  " . implode("\n  ", $urls));

            return Command::FAILURE;
        }

        $url = $urls[0];
        $actual = $establecimiento->medio(PmsEstablecimientoMediaTipo::MAPA)?->getValor();

        if ($actual !== null && $actual !== '' && $actual !== $url) {
            $io->error(sprintf('El establecimiento ya tiene otro mapa (%s) y la guía dice %s. Decide cuál vale.', $actual, $url));

            return Command::FAILURE;
        }

        $io->table(['Ficha', 'Mapa'], array_map(static fn (PmsGuiaItem $i): array => [(string) $i->getNombreInterno(), $url], $fichas));

        if ($simular) {
            $io->note(sprintf('Simulación: se guardaría %s en «%s» y las fichas dirían {{ mapa }}.', $url, $establecimiento->getNombreComercial()));

            return Command::SUCCESS;
        }

        if ($actual === null || $actual === '') {
            $this->em->persist((new PmsEstablecimientoMedia())
                ->setEstablecimiento($establecimiento)
                ->setTipo(PmsEstablecimientoMediaTipo::MAPA)
                ->setUrl($url));
        }

        foreach ($fichas as $item) {
            $item->setDescripcion(array_map(
                static fn (array $fila): array => ['content' => preg_replace(self::MARCADOR, '{{ mapa }}', (string) ($fila['content'] ?? '')) ?? ''] + $fila,
                $item->getDescripcion() ?? []
            ));

            if ($item->getAgenteContenido() !== null) {
                $item->setAgenteContenido(preg_replace(self::MARCADOR, '{{ mapa }}', $item->getAgenteContenido()));
            }

            $this->sellar($item);
        }

        $this->em->flush();
        $io->success(sprintf('Mapa guardado en «%s»; %d ficha(s) dicen ahora {{ mapa }}.', $establecimiento->getNombreComercial(), \count($fichas)));

        return Command::SUCCESS;
    }

    private function resellar(SymfonyStyle $io, bool $simular): int
    {
        $fichas = array_values(array_filter(
            $this->em->getRepository(PmsGuiaItem::class)->findAll(),
            static fn (PmsGuiaItem $i): bool => str_contains(json_encode($i->getDescripcion()) ?: '', '{{ mapa }}'),
        ));

        $io->listing(array_map(static fn (PmsGuiaItem $i): string => (string) $i->getNombreInterno(), $fichas));

        if ($simular || $fichas === []) {
            $io->note($simular ? 'Simulación: no se ha escrito nada.' : 'Ninguna ficha dice {{ mapa }}.');

            return Command::SUCCESS;
        }

        array_walk($fichas, $this->sellar(...));
        $this->em->flush();
        $io->success(sprintf('%d ficha(s) reselladas.', \count($fichas)));

        return Command::SUCCESS;
    }

    /**
     * Marca las traducciones como hechas desde el español ACTUAL, sin llamar al traductor. Se les
     * quita antes la huella vieja: el sellado sólo escribe donde no hay ninguna.
     */
    private function sellar(PmsGuiaItem $item): void
    {
        $item->setDescripcion(array_map(
            static function (array $fila): array {
                $huella = $fila['origenHash'] ?? null;

                // Una traducción curada a mano conserva su centinela: no se retraduce nunca.
                if (($fila['language'] ?? '') === 'es' || $huella === AutoTranslationService::HASH_MANUAL) {
                    return $fila;
                }

                return array_filter($fila, static fn (string $clave): bool => $clave !== 'origenHash', \ARRAY_FILTER_USE_KEY);
            },
            $item->getDescripcion() ?? []
        ));

        $item->setEjecutarTraduccion(false);
        $this->traductor->processEntity($item, forceExecution: true, soloSellar: true);
    }
}
