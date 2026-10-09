<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pivotes «globales» que en realidad sólo valían el día 1.
 *
 * ── El síntoma ──────────────────────────────────────────────────────────────
 *
 * El retorno compartido de Ollantaytambo se puso en el día 2 de una cotización y llegó «sin
 * componentes vinculados». Su hermano, el que deja en el hotel, sí traía el suyo. La única
 * diferencia entre los dos pivotes era una columna:
 *
 * ```
 * TRANS_DIRECT_RET-MAPI-OLL_CENTRO   contexto NULL · día 1      ← no entra el día 2
 * TRANS_DIRECT_RET-MAPI-OLL_CUZ      contexto NULL · día NULL   ← entra cualquier día
 * ```
 *
 * Es la matriz de `docs/Travel.md` §4: `null` significa «no filtres por esto», y un `dia` puesto
 * sin plantilla quiere decir **«sólo cuando el segmento cae ese día, en cualquier plantilla»**.
 * Un traslado se usa cualquier día —el bus de vuelta de Puno nunca cae el día 1 de un paquete
 * de dos—, así que con `dia = 1` se quedaba sin componente casi siempre.
 *
 * ── De dónde venía ──────────────────────────────────────────────────────────
 *
 * El doc define «global» como contexto NULL **y** día NULL —«la logística global del pool, el
 * caso normal, 63 %»—, pero el esqueleto de código del mismo doc y el comando de referencia
 * (`CrearEscalaMirafloresCommand`) le ponían `->setDia(1)` a la relación global. Los comandos de
 * carga se escriben copiando ese esqueleto, así que el error se propagó. Los dos están
 * corregidos; esto deshace lo que ya se había escrito.
 *
 * ── El alcance, en dos tandas ───────────────────────────────────────────────
 *
 * **Primero lo cargado el 08–09/10/2026**: cuatrimotos de Maras, Titicaca, la conexión de Puno
 * y el retorno compartido. Ahí los traslados fallaban de verdad.
 *
 * **Después los 21 del mismo linaje** —resort de Punta Cana, Miraflores, Saona, Coco Bongo—,
 * revisados uno por uno contra las cotizaciones reales. Resultado: los 75 usos están todos en el
 * día 1, **ninguno ha fallado**. No por el día 1 sino porque el resort se cotiza con un
 * cotservicio por día, así que hasta el check-out del quinto día cae en el día 1 de su
 * cotservicio. Se pasan a NULL igual: ese día no lo decidió nadie —es el mismo artefacto del
 * esqueleto—, con NULL siguen entrando donde entran hoy, y el día que un resort se arme como
 * cotservicio de varios días, el check-out y el día libre no se quedarán vacíos.
 *
 * ⚠️ **Sólo el día 1.** Hay pivotes globales con `dia = 2` que sí son intencionados —el doc los
 * cuenta, 18— y ése es un filtro que alguien quiso. El día 1 era el copiado.
 *
 * Quitar el día no duplica nada en las plantillas: el pivote de CONTEXTO, el que fija la hora,
 * sigue en su sitio y es el que manda dentro de ellas.
 */
#[AsCommand(
    name: 'app:travel:quitar-dia-a-pivotes-globales',
    description: 'Quita el día 1 a los pivotes globales de las cargas recientes, que los dejaba sin componente.',
    hidden: true,
)]
final class QuitarDiaAPivotesGlobalesCommand extends Command
{
    /**
     * Los segmentos que cubre, por patrón de slug. Son los de los comandos que copiaron el
     * esqueleto con `setDia(1)`.
     *
     * @var list<string>
     */
    private const ALCANCE = [
        // las cargas de octubre
        '%ATV_MARAS%',
        '%TITICACA%',
        'TRANS-%',
        'REC-%',
        'TRANS_DIRECT_RET-MAPI-OLL_CENTRO',
        // el mismo linaje, revisado contra las cotizaciones reales
        'ACT-RESORT-%',
        'ALM-RESORT-%',
        'CEN-RESORT-%',
        'DES-RESORT-%',
        'ALM-WALK_MIR-%',
        'DES-APT_LIM',
        'VIS-COCO_BONGO_PUJ-%',
        'VIS-SAONA-%',
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

        $io->title('Pivotes globales con día');

        $qb = $this->em->getRepository(TravelSegmentoComponente::class)->createQueryBuilder('sc')
            ->join('sc.segmento', 's')->addSelect('s')
            ->join('sc.componente', 'c')->addSelect('c')
            ->where('sc.itinerarioContexto IS NULL')
            ->andWhere('sc.dia = 1');

        $patrones = $qb->expr()->orX();

        foreach (self::ALCANCE as $i => $patron) {
            $patrones->add($qb->expr()->like('s.slug', ':p' . $i));
            $qb->setParameter('p' . $i, $patron);
        }

        /** @var list<TravelSegmentoComponente> $pivotes */
        $pivotes = $qb->andWhere($patrones)->orderBy('s.slug')->getQuery()->getResult();

        $tocados = 0;

        foreach ($pivotes as $pivote) {
            $segmento = $pivote->getSegmento();
            $componente = $pivote->getComponente();

            // Si ya hay un gemelo global sin día, éste sobra: ponerle NULL lo duplicaría.
            $gemelo = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                'segmento' => $segmento,
                'componente' => $componente,
                'itinerarioContexto' => null,
                'dia' => null,
            ]);

            ++$tocados;
            $io->text(sprintf(
                '  %s · %-36s %-46s día %d → %s',
                $simula ? 'haría ' : 'hecho ',
                $segmento?->getSlug() ?? '?',
                $componente?->getNombreInterno() ?? '?',
                $pivote->getDia(),
                $gemelo !== null ? 'sobra, ya hay uno sin día' : 'cualquier día',
            ));

            if ($simula) {
                continue;
            }

            if ($gemelo !== null) {
                $this->em->remove($pivote);
            } else {
                $pivote->setDia(null);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d pivote(s).', $simula ? 'Se corregirían' : 'Corregidos', $tocados));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
