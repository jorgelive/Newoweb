<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dos guiones de Machu Picchu que faltaban: el de Poroy y el de dos días sin bimodal.
 *
 * ── Qué faltaba y por qué ───────────────────────────────────────────────────
 *
 * **Poroy no tenía plantilla ninguna**, y no sólo los traslados: sus trenes también estaban
 * sueltos en el pool. Una salida por Poroy había que armarla segmento a segmento en cada
 * cotización. Se vende poco, pero el guion cuesta lo mismo tenerlo que no tenerlo.
 *
 * **Y las dos plantillas que había usaban bimodal**, cuando lo que más se vende en dos días es
 * el traslado DIRECTO del hotel a Ollantaytambo. Los segmentos ya existían —
 * `TRANS_DIRECT_SAL-MAPI-CUZ_OLL` y `TRANS_DIRECT_RET-MAPI-OLL_CUZ`— sin ningún guion que los
 * usara.
 *
 * ── Las horas van POR PLANTILLA, y por eso esto no es sólo crear `rel` ──────
 *
 * Es la parte que no se ve al mirar una plantilla en el panel: cada una fija sus propias horas
 * con un `TravelSegmentoComponente` de contexto. El mismo `SUBBAJ_BUS-MAPI-SUB` sube a las
 * 10:00 en el full day y a las 07:00 en el de dos días, porque quien duerme arriba entra al
 * santuario temprano.
 *
 * Este comando **copia los pivotes globales de cada segmento al contexto de la plantilla** y
 * sólo cambia la hora donde hace falta. Así no hay que repetir aquí qué componente lleva cada
 * segmento —ya lo dice el catálogo— y si mañana se le añade uno, entra solo.
 *
 * ⚠️ **Una hora inventada, y va avisada:** el traslado directo de ida tiene 12:00 como hora
 * global, que sirve a los trenes de tarde de otros productos. Delante de un tren de las 07:45
 * ordenaría el día al revés, así que esta plantilla lo pone a las **05:30**. Es un supuesto
 * operativo —dos horas de Cusco a Ollantaytambo más margen— que conviene confirmar.
 *
 * El retorno directo se queda con su hora global, 18:20, que ya es posterior al tren de las
 * 14:30: ahí no hace falta inventar nada.
 */
#[AsCommand(
    name: 'app:travel:crear-plantillas-mapi',
    description: 'Crea la plantilla de Machu Picchu por Poroy y la de dos días con traslado directo.',
    hidden: true,
)]
final class CrearPlantillasMapiCommand extends Command
{
    private const SERVICIO = 'MAPI';

    /**
     * `segmentos` es slug, día y orden. `horas` sobrescribe la hora del pivote copiado: la clave
     * es el slug del segmento, o `slug|componente` cuando dos componentes del mismo segmento no
     * van a la misma hora.
     *
     * @var list<array{slug: string, nombre: string, titulo: string, dias: int,
     *                 segmentos: list<array{0: string, 1: int, 2: int}>,
     *                 horas: array<string, string>}>
     */
    private const PLANTILLAS = [
        [
            'slug' => '1D MAPI: CUZ PORO MAPI PORO CUZ (BM)',
            'nombre' => 'Full Day MAPI: CUZ PORO MAPI PORO CUZ (bimodal)',
            'titulo' => 'Machu Picchu en el día, por Poroy',
            'dias' => 1,
            'segmentos' => [
                ['TRANS_BIM_IDA-MAPI-HTL_WAN_POR', 1, 1],
                ['TRANS_BIM_IDA-MAPI-CUZ_POR', 1, 2],
                ['TREN_IDA-MAPI-POR', 1, 3],
                ['CONTACT-MAPI-EST_MAPI', 1, 4],
                ['SUBBAJ_BUS-MAPI-SUB', 1, 5],
                ['VIS-MAPI-C2', 1, 6],
                ['SUBBAJ_BUS-MAPI-BAJ', 1, 7],
                ['TREN_RTN-MAPI-POR', 1, 8],
                ['TRANS_BIM_RET-MAPI-POR_CUZ', 1, 9],
                ['TRANS_BIM_RET-MAPI-POR_HTL', 1, 10],
            ],
            // Las horas globales de Poroy ya son las suyas (05:20, 05:40, 06:40, 15:32, 19:05,
            // 19:40) y las de Machu Picchu coinciden con las del full day por Ollanta. Nada que
            // sobrescribir: es la ventaja de copiar del catálogo en vez de repetirlo aquí.
            'horas' => [],
        ],
        [
            'slug' => '2D MAPI: CUZ OLLA MAPI OLLA CUZ (DIR)',
            'nombre' => 'Two Day MAPI: CUZ OLLA MAPI OLLA CUZ (traslado directo)',
            'titulo' => 'Machu Picchu en dos días, con traslado directo',
            'dias' => 2,
            'segmentos' => [
                ['TRANS_DIRECT_SAL-MAPI-CUZ_OLL', 1, 1],
                ['TREN_IDA-MAPI-OLL', 1, 2],
                ['ALO-MACHU', 1, 3],
                ['CONTACT-MAPI-HTL', 2, 1],
                ['SUBBAJ_BUS-MAPI-SUB', 2, 2],
                ['VIS-MAPI-C2', 2, 3],
                ['SUBBAJ_BUS-MAPI-BAJ', 2, 4],
                ['TREN_RTN-MAPI-OLL', 2, 5],
                ['TRANS_DIRECT_RET-MAPI-OLL_CUZ', 2, 6],
            ],
            'horas' => [
                // ⚠️ Supuesto: la global son las 12:00 y delante de un tren de las 07:45
                // ordenaría el día al revés.
                'TRANS_DIRECT_SAL-MAPI-CUZ_OLL' => '05:30',
                // El resto, copiadas de «2D MAPI: OLLA MAPI OLLA CUZ (BM)», que es su hermana.
                'SUBBAJ_BUS-MAPI-SUB' => '07:00',
                'VIS-MAPI-C2|Ingreso a Machu Picchu' => '08:00',
                'VIS-MAPI-C2|Guiado Machu Picchu' => '08:30',
                'SUBBAJ_BUS-MAPI-BAJ' => '11:00',
                'TREN_RTN-MAPI-OLL' => '14:30',
            ],
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
        $simula = (bool) $input->getOption('dry-run');

        $servicio = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO]);

        if ($servicio === null) {
            $io->error(sprintf('No existe el servicio %s.', self::SERVICIO));

            return Command::FAILURE;
        }

        $io->title('Plantillas de Machu Picchu');
        $creadas = 0;

        foreach (self::PLANTILLAS as $def) {
            $io->section($def['nombre']);

            if ($this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text('  ya existe.');
                continue;
            }

            $segmentos = [];
            $falta = false;

            foreach ($def['segmentos'] as [$slug, , ]) {
                $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);

                if ($segmento === null) {
                    $io->error(sprintf('  falta el segmento «%s».', $slug));
                    $falta = true;
                    continue;
                }

                $segmentos[$slug] = $segmento;
            }

            if ($falta) {
                return Command::FAILURE;
            }

            ++$creadas;

            $itinerario = null;

            if (!$simula) {
                $itinerario = (new TravelItinerario())
                    ->setServicio($servicio)
                    ->setSlug($def['slug'])
                    ->setNombreInterno($def['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                    ->setDuracionDias($def['dias']);

                $this->em->persist($itinerario);
            }

            foreach ($def['segmentos'] as [$slug, $dia, $orden]) {
                $segmento = $segmentos[$slug];

                // Los pivotes GLOBALES del segmento dicen qué componentes lleva y a qué hora.
                // Se copian al contexto de esta plantilla, que es como el catálogo expresa «aquí
                // esto ocurre a esta otra hora». Así no hay que enumerar componentes a mano.
                /** @var list<TravelSegmentoComponente> $globales */
                $globales = $this->em->getRepository(TravelSegmentoComponente::class)
                    ->findBy(['segmento' => $segmento, 'itinerarioContexto' => null, 'dia' => null]);

                $detalle = [];

                foreach ($globales as $global) {
                    $componente = $global->getComponente();
                    $hora = $def['horas'][$slug . '|' . ($componente?->getNombreInterno() ?? '')]
                        ?? $def['horas'][$slug]
                        ?? $global->getHora()?->format('H:i');

                    $detalle[] = ($componente?->getNombreInterno() ?? '?') . ' ' . ($hora ?? '—');

                    if ($simula) {
                        continue;
                    }

                    $pivote = (new TravelSegmentoComponente())
                        ->setSegmento($segmento)
                        ->setComponente($componente)
                        ->setItinerarioContexto($itinerario)
                        ->setTarifaPredeterminada($global->getTarifaPredeterminada())
                        ->setModo($global->getModo())
                        ->setOrden($global->getOrden());

                    if ($hora !== null) {
                        $pivote->setHora(new \DateTimeImmutable($hora));
                    }

                    $this->em->persist($pivote);
                }

                $io->text(sprintf(
                    '  día %d · %-2d %-32s %s',
                    $dia,
                    $orden,
                    $slug,
                    $detalle === [] ? 'sin componente' : implode(' · ', $detalle),
                ));

                if ($simula) {
                    continue;
                }

                $this->em->persist(
                    (new TravelItinerarioSegmentoRel())
                        ->setItinerario($itinerario)
                        ->setSegmento($segmento)
                        ->setDia($dia)
                        ->setOrden($orden),
                );
            }

            if (!$simula) {
                $this->em->flush();
            }
        }

        $io->newLine();
        $io->success(sprintf('%s %d plantilla(s).', $simula ? 'Se crearían' : 'Creadas', $creadas));

        $io->note('La hora del traslado directo de ida (05:30) es un supuesto: confírmala.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
