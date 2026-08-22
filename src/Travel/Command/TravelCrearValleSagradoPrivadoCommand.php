<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crea la plantilla que le faltaba a la variante privada del Valle Sagrado.
 *
 * ── Qué se quedó a medias ───────────────────────────────────────────────────
 * El segmento «Recojo en el Hotel (Servicio Privado)» con su logística completa —Guiado Valle
 * Sagrado + Transporte Valle Sagrado + BTPV— estaba montado y ofrecido en el pool del servicio
 * «Valle Sagrado», pero **ninguna plantilla lo usaba**. La privada que sí existe,
 * «Full Day Valle sagrado tradicional privado», arranca con otro segmento y otra logística
 * (Super Valle), así que ésta nunca llegó a tener la suya.
 *
 * ⚠️ **Ojo con «VIP» en los nombres de este catálogo: es etiqueta comercial, no nivel.** Lo que
 * se llama VIP es el pool más barato. No sirve para deducir qué variante es más privada ni cuál
 * debería ser la principal.
 *
 * ── Se CLONA la estructura de la privada existente ──────────────────────────
 * Y sólo se cambia el segmento de recojo. Así los segmentos o2..o7 son **exactamente las mismas
 * filas** —Pisac, Urubamba, Ollantaytambo, Chinchero, Descanso en el Valle— y no copias nuevas.
 * Es lo que hace que corregir el punto de un segmento se refleje en las tres plantillas del Valle
 * a la vez, que es el motivo por el que los puntos viven en el segmento.
 *
 * El título público se copia tal cual del modelo, con sus siete idiomas: comercialmente las dos
 * SON «excursión privada al Valle Sagrado». Lo que las distingue —qué transporte y qué guía— es
 * operativo y vive en `nombreInterno`, que es lo que ve quien cotiza.
 *
 * La marca de servicio principal NO se pone aquí: la pone
 * {@see TravelPromoverServicioPrincipalCommand}, que es donde vive la tabla de «qué componente ES
 * el día» y ya sabe añadir la fila sin tocar la global.
 */
#[AsCommand(
    name: 'app:travel:crear-valle-sagrado-privado',
    description: 'Crea la plantilla «Full Day Valle Sagrado privado» que se quedó sin montar.',
)]
final class TravelCrearValleSagradoPrivadoCommand extends Command
{
    private const string NOMBRE_NUEVA = 'Full Day Valle Sagrado privado';
    private const string SLUG_NUEVA = '1D VALLE PRIV VS';
    // Renombrada por TravelRenombrarValleVipPrivadaCommand: era «…tradicional privado».
    private const string MODELO = 'Full Day Valle Vip privada';
    private const string SEGMENTO_RECOJO = 'Recojo en el Hotel (Servicio Privado)';
    /** Lo que distingue al segmento de recojo correcto de su homónimo. */
    private const string COMPONENTE_CLAVE = 'Transporte Valle Sagrado';

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
        $repoItin = $this->em->getRepository(TravelItinerario::class);

        if ($repoItin->findOneBy(['nombreInterno' => self::NOMBRE_NUEVA]) !== null) {
            $io->success(sprintf('«%s» ya existe. Nada que hacer.', self::NOMBRE_NUEVA));

            return Command::SUCCESS;
        }

        $modelo = $repoItin->findOneBy(['nombreInterno' => self::MODELO]);

        if ($modelo === null) {
            $io->error(sprintf('No encuentro la plantilla modelo «%s».', self::MODELO));

            return Command::FAILURE;
        }

        $recojo = $this->segmentoDeRecojo();

        if ($recojo === null) {
            $io->error(sprintf(
                'No encuentro el segmento «%s» con el componente «%s».',
                self::SEGMENTO_RECOJO,
                self::COMPONENTE_CLAVE
            ));

            return Command::FAILURE;
        }

        $nueva = new TravelItinerario();
        $nueva->setServicio($modelo->getServicio());
        $nueva->setNombreInterno(self::NOMBRE_NUEVA);
        $nueva->setSlug(self::SLUG_NUEVA);
        $nueva->setDuracionDias($modelo->getDuracionDias());
        $nueva->setTitulo($modelo->getTitulo());

        $io->section(self::NOMBRE_NUEVA);
        $io->writeln(sprintf('  servicio: %s · %d día/s · slug %s', $modelo->getServicio(), $modelo->getDuracionDias(), self::SLUG_NUEVA));
        $io->newLine();

        /** @var list<TravelItinerarioSegmentoRel> $relsModelo */
        $relsModelo = $modelo->getItinerarioSegmentos()->toArray();

        usort(
            $relsModelo,
            static fn (TravelItinerarioSegmentoRel $a, TravelItinerarioSegmentoRel $b): int
                => [$a->getDia(), $a->getOrden()] <=> [$b->getDia(), $b->getOrden()]
        );

        foreach ($relsModelo as $relModelo) {
            $esPrimero = $relModelo === $relsModelo[0];
            $segmento = $esPrimero ? $recojo : $relModelo->getSegmento();

            $rel = new TravelItinerarioSegmentoRel();
            $rel->setItinerario($nueva);
            $rel->setSegmento($segmento);
            $rel->setDia($relModelo->getDia());
            $rel->setOrden($relModelo->getOrden());

            if (!$seco) {
                $this->em->persist($rel);
            }

            $io->writeln(sprintf(
                '  o%-2d %-44s%s',
                $relModelo->getOrden(),
                mb_substr((string) $segmento?->getNombreInterno(), 0, 43),
                $esPrimero ? '  <info>← el recojo que cambia</info>' : ''
            ));
        }

        if (!$seco) {
            $this->em->persist($nueva);
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            'Plantilla %s. Falta marcarle el servicio principal: app:travel:promover-servicio-principal',
            $seco ? 'se crearía' : 'creada'
        ));

        return Command::SUCCESS;
    }

    /**
     * El segmento de recojo correcto entre sus homónimos.
     *
     * Hay **dos** segmentos llamados «Recojo en el Hotel (Servicio Privado)» y sólo se distinguen
     * por lo que llevan colgado: uno el de Chinchero/Maras, otro el del Valle. Buscar por nombre a
     * secas cogería cualquiera de los dos según el orden que devolviera la base, y montaría la
     * plantilla con la logística equivocada sin dar ningún error.
     */
    private function segmentoDeRecojo(): ?TravelSegmento
    {
        $componente = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombre' => self::COMPONENTE_CLAVE]);

        if ($componente === null) {
            return null;
        }

        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $componente]) as $fila) {
            $segmento = $fila->getSegmento();

            if ($segmento?->getNombreInterno() === self::SEGMENTO_RECOJO) {
                return $segmento;
            }
        }

        return null;
    }
}
