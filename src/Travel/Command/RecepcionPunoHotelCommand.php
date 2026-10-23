<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La recepción en Puno dice lo que de verdad pasa: del terminal a un hotel donde esperar (23/10/2026).
 *
 * Los dos segmentos se llamaban `REC-TITICACA-…` porque nacieron con el tour del lago, pero viven en
 * «Transporte en Puno» y sirven a cualquier cosa que empiece allí. Y contaban otra cosa: «después
 * les llevamos al punto de inicio de la excursión». Jorge: «lo que hace es que cuando llegan los
 * reciben y los transportan a un hotel en Puno donde pueden desayunar y esperar el recojo».
 *
 * ```
 * REC-TITICACA-TERM_PUN      →  REC-PUNO-TERM_PUN       desde el Terminal Terrestre
 * REC-TITICACA-TERM_CDS_PUN  →  REC-PUNO-TERM_CDS_PUN   desde el terminal de Cruz del Sur
 * ```
 *
 * El componente cambia igual de nombre y de título: su título público es la etiqueta «Incluye:» que
 * lee el cliente, y decía «…al punto de inicio del Tour».
 *
 * - **El desayuno va por su cuenta**, como decía el texto anterior: el hotel es un sitio donde
 *   esperar, no un servicio incluido.
 * - **Sin aseo ni custodia**: el texto del Terminal Terrestre los prometía y ninguna fuente dice
 *   que el hotel de espera los dé.
 * - **El destino sigue «sin definir»**: no es el alojamiento del pasajero —puede no dormir en
 *   Puno—, y marcarlo como tal envenenaría el «dónde recojo» de lo que viene después.
 *
 * Idempotente: segmentos por slug viejo o nuevo, componente por nombre viejo o nuevo.
 */
#[AsCommand(
    name: 'app:travel:recepcion-puno-hotel',
    description: 'Renombra y redacta la recepción en Puno: del terminal a un hotel donde esperar.',
    hidden: true,
)]
final class RecepcionPunoHotelCommand extends Command
{
    private const string COMPONENTE_ANTES = 'Transporte Term Puno - Punto de Inicio';
    private const string COMPONENTE = 'Transporte Terminal Puno → Hotel de espera';
    private const string COMPONENTE_TITULO = 'Traslado del terminal de Puno al hotel';

    /** @var list<array{antes: string, slug: string, nombre: string, titulo: string, contenido: string}> */
    private const array SEGMENTOS = [
        [
            'antes' => 'REC-TITICACA-TERM_PUN',
            'slug' => 'REC-PUNO-TERM_PUN',
            'nombre' => 'Recepción en el Terminal Terrestre de Puno y traslado al hotel',
            'titulo' => 'Llegada a Puno',
            'contenido' => 'Les esperamos a la llegada del bus en el Terminal Terrestre y les llevamos a un '
                . 'hotel de Puno, donde pueden desayunar —va por su cuenta— y esperar tranquilos a que les '
                . 'recojan.',
        ],
        [
            'antes' => 'REC-TITICACA-TERM_CDS_PUN',
            'slug' => 'REC-PUNO-TERM_CDS_PUN',
            'nombre' => 'Recepción en el terminal de Cruz del Sur en Puno y traslado al hotel',
            'titulo' => 'Llegada a Puno',
            'contenido' => 'Les esperamos a la llegada del bus en el terminal de Cruz del Sur, en el jirón 1 '
                . 'de Mayo, y les llevamos a un hotel de Puno, donde pueden desayunar —va por su cuenta— y '
                . 'esperar tranquilos a que les recojan.',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $repo = $this->em->getRepository(TravelSegmento::class);

        foreach (self::SEGMENTOS as $def) {
            $segmento = $repo->findOneBy(['slug' => $def['antes']]) ?? $repo->findOneBy(['slug' => $def['slug']]);
            if (!$segmento instanceof TravelSegmento) {
                $io->error(sprintf('No encuentro %s ni %s.', $def['antes'], $def['slug']));

                return Command::FAILURE;
            }

            $titulo = $segmento->getTitulo()[0]['content'] ?? null;
            $contenido = $segmento->getContenido()[0]['content'] ?? null;
            if ($segmento->getSlug() === $def['slug'] && $titulo === $def['titulo'] && $contenido === $def['contenido']) {
                $io->text(sprintf('· %s — ya está', $def['slug']));
                continue;
            }

            $io->text(sprintf('· %s → %s — %s', $segmento->getSlug(), $def['slug'], $seco ? 'se reescribiría' : 'reescrito'));
            if (!$seco) {
                $segmento->setSlug($def['slug'])
                    ->setNombreInterno($def['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                    ->setContenido([['language' => 'es', 'content' => $def['contenido']]]);
            }
        }

        $componentes = $this->em->getRepository(TravelComponente::class);
        $componente = $componentes->findOneBy(['nombreInterno' => self::COMPONENTE_ANTES])
            ?? $componentes->findOneBy(['nombreInterno' => self::COMPONENTE]);
        if (!$componente instanceof TravelComponente) {
            $io->error('No encuentro el componente de la recepción en Puno.');

            return Command::FAILURE;
        }

        if ($componente->getNombreInterno() !== self::COMPONENTE) {
            $io->text(sprintf('· Componente «%s» → «%s» — %s', $componente->getNombreInterno(), self::COMPONENTE, $seco ? 'se renombraría' : 'renombrado'));
            if (!$seco) {
                $componente->setNombreInterno(self::COMPONENTE)
                    ->setTitulo([['language' => 'es', 'content' => self::COMPONENTE_TITULO]]);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
