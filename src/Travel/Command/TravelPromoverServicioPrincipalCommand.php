<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da servicio principal a las plantillas que se quedaron sin él.
 *
 * ── Por qué les faltaba ─────────────────────────────────────────────────────
 * Son anteriores al campo. Alguien intentó marcarlas cuando `horaServicioCompleto` aún no exigía
 * plantilla, y esas marcas quedaron como filas globales que no significaban nada —se retiraron
 * con `app:travel:limpiar-promociones-huerfanas`—. Esto pone la marca donde sí vale.
 *
 * ── ⚠️ Se AÑADE una fila, no se modifica la global ──────────────────────────
 * Y es la parte que importa. `itinerarioContexto = null` significa «este componente se inyecta
 * siempre que se use el segmento» (ver `docs/Travel.md` §4). Ponerle plantilla a esa fila
 * restringiría la inyección y el componente dejaría de entrar en un servicio armado a medida que
 * reutilice el segmento — sin aviso y sin error.
 *
 * Así que se replica el patrón que **ya está vivo en producción** con `Pool Valle Sagrado`: dos
 * filas para el mismo componente y segmento, una global sin marca —que sigue inyectando— y otra
 * ligada a la plantilla con la marca. El resto de la configuración se copia tal cual de la
 * global, para que la fila nueva no invente ni horas ni tarifas.
 */
#[AsCommand(
    name: 'app:travel:promover-servicio-principal',
    description: 'Marca el servicio principal del día en las plantillas que se quedaron sin él.',
)]
final class TravelPromoverServicioPrincipalCommand extends Command
{
    /**
     * Plantilla → componente que ES su día.
     *
     * Explícito y no deducido: «cuál de estos componentes abarca el día» es una decisión de
     * producto, y una regla automática la acertaría casi siempre, que es la peor frecuencia —
     * nadie revisa lo que casi siempre funciona.
     *
     * @var array<string, string>
     */
    private const array PROMOCIONES = [
        'Full Day Vinicunca Estandar' => 'Pool Vinicunca',
        'Half Day Chinchero, Maras y Moray' => 'Pool Maras Moray',
        'Half Day Chinchero, Maras y Moray privado' => 'Transporte Maras',
        // La plantilla que montó TravelCrearValleSagradoPrivadoCommand.
        'Full Day Valle Sagrado privado' => 'Transporte Valle Sagrado',
        // Antes «…tradicional privado»: su logística es Super Valle, no la del Valle a secas.
        'Full Day Valle Vip privada' => 'Transporte Super Valle',
    ];

    /**
     * Plantilla de VARIOS días → [día → componente que ES ese día].
     *
     * ⚠️ **Con el día, siempre.** Un principal sin día vale para TODOS los días de la plantilla
     * (así lo lee `TravelSegmentoComponentePromocionUnicaListener`): marcar la caminata del
     * Camino Inca sin día le daba sus horas, 07:30–15:00, también al día de Machu Picchu.
     *
     * Y un día sólo entra aquí si de verdad hay un servicio que lo abarque —el paquete de un
     * proveedor externo—. Un día de tren + bus + ingreso + guía se queda sin principal a
     * propósito: cada componente saca sus puntos de su propio segmento.
     *
     * @var array<string, array<int, string>>
     */
    private const array PROMOCIONES_POR_DIA = [
        // Día 1: la caminata del km 104, que es el paquete del operador del Camino. El día 2 es
        // Machu Picchu clásico y no lleva principal. Comprobado el 17/10/2026.
        'Two Day Camino inca' => [1 => 'Pool Camino Inca 2d'],
    ];

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
        $creadas = 0;

        foreach (self::PROMOCIONES as $nombrePlantilla => $nombreComponente) {
            $io->section(sprintf('%s → %s', $nombrePlantilla, $nombreComponente));

            $itinerario = $this->em->getRepository(TravelItinerario::class)
                ->findOneBy(['nombreInterno' => $nombrePlantilla]);
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $nombreComponente]);

            if ($itinerario === null || $componente === null) {
                $io->writeln('  <error>✗</error> no encuentro la plantilla o el componente');
                continue;
            }

            $segmentos = $this->segmentosDe($itinerario);
            $global = null;

            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $componente]) as $fila) {
                $segmentoId = $fila->getSegmento()?->getId()?->toRfc4122();

                if ($segmentoId === null || !isset($segmentos[$segmentoId])) {
                    continue;
                }

                if ($fila->getItinerarioContexto()?->getId()?->toRfc4122() === $itinerario->getId()?->toRfc4122()) {
                    $io->writeln('  <comment>·</comment> ya tiene fila ligada a esta plantilla — sin tocar');
                    continue 2;
                }

                if ($fila->getItinerarioContexto() === null) {
                    $global = $fila;
                }
            }

            if ($global === null) {
                $io->writeln('  <error>✗</error> no hay fila global de ese componente en los segmentos de esta plantilla');
                continue;
            }

            $nueva = clone $global;
            // `__clone()` desvincula el segmento a propósito (espera que el padre lo re-enlace);
            // aquí no hay padre nuevo, es el mismo segmento.
            $nueva->setSegmento($global->getSegmento());
            $nueva->setItinerarioContexto($itinerario);
            $nueva->setHoraServicioCompleto(true);

            if (!$seco) {
                $this->em->persist($nueva);
            }

            ++$creadas;
            $io->writeln(sprintf(
                '  <info>+</info> fila nueva en «%s» (día %s, orden %d, modo %s) marcada como principal',
                mb_substr((string) $global->getSegmento()?->getNombreInterno(), 0, 45),
                $global->getDia() ?? 'todos',
                $global->getOrden(),
                $global->getModo()->value
            ));
        }

        $creadas += $this->promoverPorDia($io, $seco);

        if (!$seco) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d %s. La fila global se queda como estaba: la inyección no cambia.',
            $creadas,
            $seco ? 'promociones se harían' : 'promociones hechas'
        ));

        return Command::SUCCESS;
    }

    /**
     * Las promociones de {@see self::PROMOCIONES_POR_DIA}.
     *
     * Distinto del caso de un día en una cosa: **si la plantilla ya tiene su fila, se marca ésa**
     * en vez de saltarla. En las de varios días es lo normal —la fila propia existe para llevar las
     * horas de ESA plantilla— y saltarla dejaba el día sin principal para siempre, con el comando
     * diciendo que no había nada que hacer. Sólo si no la tiene se clona la global, como arriba.
     *
     * Idempotente: una fila ya marcada con ese día no se toca.
     */
    private function promoverPorDia(SymfonyStyle $io, bool $seco): int
    {
        $cambios = 0;

        foreach (self::PROMOCIONES_POR_DIA as $nombrePlantilla => $porDia) {
            $itinerario = $this->em->getRepository(TravelItinerario::class)
                ->findOneBy(['nombreInterno' => $nombrePlantilla]);

            if ($itinerario === null) {
                $io->writeln(sprintf('  <error>✗</error> no encuentro la plantilla «%s»', $nombrePlantilla));
                continue;
            }

            $diasDeSegmento = $this->diasDeSegmentos($itinerario);

            foreach ($porDia as $dia => $nombreComponente) {
                $io->section(sprintf('%s · día %d → %s', $nombrePlantilla, $dia, $nombreComponente));

                $componente = $this->em->getRepository(TravelComponente::class)
                    ->findOneBy(['nombreInterno' => $nombreComponente]);

                if ($componente === null) {
                    $io->writeln('  <error>✗</error> no encuentro el componente');
                    continue;
                }

                $propia = null;
                $global = null;

                foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $componente]) as $fila) {
                    $segmentoId = $fila->getSegmento()?->getId()?->toRfc4122();

                    // Sólo los segmentos que en ESTA plantilla caen ese día.
                    if ($segmentoId === null || !\in_array($dia, $diasDeSegmento[$segmentoId] ?? [], true)) {
                        continue;
                    }

                    if ($fila->getItinerarioContexto()?->getId()?->toRfc4122() === $itinerario->getId()?->toRfc4122()) {
                        $propia = $fila;
                    } elseif ($fila->getItinerarioContexto() === null) {
                        $global = $fila;
                    }
                }

                if ($propia !== null && $propia->isHoraServicioCompleto() && $propia->getDia() === $dia) {
                    $io->writeln('  <comment>·</comment> ya es el principal de ese día — sin tocar');
                    continue;
                }

                if ($propia === null && $global === null) {
                    $io->writeln('  <error>✗</error> ese componente no está en ningún segmento de ese día de la plantilla');
                    continue;
                }

                // Llegados aquí, sin fila propia hay global (el «ninguna de las dos» salió arriba).
                if ($propia === null) {
                    $propia = clone $global;
                    $propia->setSegmento($global->getSegmento());
                    $propia->setItinerarioContexto($itinerario);

                    if (!$seco) {
                        $this->em->persist($propia);
                    }
                }

                $propia->setDia($dia);
                $propia->setHoraServicioCompleto(true);

                ++$cambios;
                $io->writeln(sprintf(
                    '  <info>+</info> «%s», día %d, %s–%s, marcada como principal',
                    mb_substr((string) $propia->getSegmento()?->getNombreInterno(), 0, 45),
                    $dia,
                    $propia->getHora()?->format('H:i') ?? '—',
                    $propia->getHoraFin()?->format('H:i') ?? '—',
                ));
            }
        }

        return $cambios;
    }

    /**
     * En qué días cae cada segmento de una plantilla (un segmento puede repetirse).
     *
     * @return array<string, list<int>>
     */
    private function diasDeSegmentos(TravelItinerario $itinerario): array
    {
        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->createQueryBuilder('r')
            ->andWhere('r.itinerario = :i')
            ->setParameter('i', $itinerario->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();

        $dias = [];

        foreach ($rels as $rel) {
            $id = $rel->getSegmento()?->getId()?->toRfc4122();

            if ($id !== null) {
                $dias[$id][] = $rel->getDia();
            }
        }

        return $dias;
    }

    /**
     * Los segmentos de una plantilla, indexados por id.
     *
     * @return array<string, true>
     */
    private function segmentosDe(TravelItinerario $itinerario): array
    {
        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->createQueryBuilder('r')
            ->andWhere('r.itinerario = :i')
            // Con el tipo explícito — ver TravelPuntosDelServicio::extremosDelDia().
            ->setParameter('i', $itinerario->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();

        $ids = [];

        foreach ($rels as $rel) {
            $id = $rel->getSegmento()?->getId()?->toRfc4122();

            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }
}
