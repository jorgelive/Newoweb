<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las horas que faltaban en las plantillas del Titicaca (16/10/2026).
 *
 * ── El día 2 de los tres 2D/1N ──────────────────────────────────────────────
 *
 * El promovido de cada 2D es del día 1 (08:00), así que el día 2 salía sin ninguna hora: el cliente
 * no sabía a qué hora vuelve a Puno, que es lo que decide si alcanza el bus. La unicidad del
 * «servicio principal del día» es `(plantilla, día)`, y el patrón de `docs/Travel.md` («Paquetes de
 * proveedor externo y plantillas de varios días») es **un componente por día, a costo 0**, sólo para
 * aportar inicio y fin. Es el primero del catálogo que se carga así.
 *
 *   07:00   desayuno con la familia (Jorge, 16/10/2026: «está bien la hora»)
 *   15:00   retorno a Puno, del tarifario de agencias 2026 de Qhapaq
 *
 * - **Tipo `pool`**, como el del día 1: con `extras` la promoción no aportaría punto de servicio
 *   (`ComponenteTipoEnum::puntosDeServicio()`), sin dar error.
 * - **Sin título público**: en un `pool` titula el segmento (`docs/TravelCargaDeCatalogo.md`).
 * - **Sólo el pivote DE PLANTILLA, sin el global**: el desayuno de Amantaní lo comparten dos
 *   plantillas, y un global colgado de él metería el segundo día de una en la otra.
 * - **Tarifa a 0 de Qhapaq**: lo que cobra Qhapaq ya está entero en el pool del día 1; ésta existe
 *   para que el componente no entre «sin tarifa».
 *
 * ── El fin del medio día de los Uros ────────────────────────────────────────
 *
 * Quedó sin fin el 10/10 «por falta de fuente». La hay: el tarifario y el itinerario oficial dan
 * «Duración: 3 horas approx.», y la plantilla sale a las 09:00 → 12:00.
 *
 * Idempotente: por nombre de componente y por lo que ya esté como se pide.
 */
#[AsCommand(
    name: 'app:travel:titicaca-segundo-dia',
    description: 'Da hora al día 2 de los 2D/1N del Titicaca y fin al medio día de los Uros.',
    hidden: true,
)]
final class TiticacaSegundoDiaCommand extends Command
{
    private const string SERVICIO = 'TITICACA';
    private const string PROVEEDOR = 'Qhapaq Adventures';
    private const string INICIO = '07:00';
    private const string FIN = '15:00';

    /** Plantilla → [componente del día 2, segmento con el que empieza ese día]. */
    private const array SEGUNDOS_DIAS = [
        '2D-TITICACA-AMANTANI' => ['Segundo día Uros Amantaní 2D1N', 'DES-TITICACA-AMANTANI'],
        '2D-TITICACA-AMANTANI-TAQUILE' => ['Segundo día Uros Amantaní Taquile 2D1N', 'DES-TITICACA-AMANTANI'],
        '2D-TITICACA-LUQUINA' => ['Segundo día Uros Taquile Luquina 2D1N', 'DES-TITICACA-LUQUINA'],
    ];

    private const string MEDIO_DIA = 'HD-TITICACA-UROS';
    private const string MEDIO_DIA_FIN = '12:00';

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

        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO]);
        $proveedor = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::PROVEEDOR]);
        $moneda = $this->em->getRepository(MaestroMoneda::class)->find('PEN');
        if (!$servicio instanceof TravelServicio || !$proveedor instanceof TravelOrganizacion || !$moneda instanceof MaestroMoneda) {
            $io->error('Falta el servicio TITICACA, el proveedor Qhapaq o la moneda PEN.');

            return Command::FAILURE;
        }

        foreach (self::SEGUNDOS_DIAS as $slug => [$nombre, $segmentoSlug]) {
            $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $slug]);
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $segmentoSlug]);
            if (!$plantilla instanceof TravelItinerario || !$segmento instanceof TravelSegmento) {
                $io->error(sprintf('Falta la plantilla «%s» o el segmento «%s».', $slug, $segmentoSlug));

                return Command::FAILURE;
            }

            if ($this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]) !== null) {
                $io->text(sprintf('· %s — ya está', $slug));
                continue;
            }

            $io->text(sprintf('· %s — %s «%s», día 2 de %s a %s', $slug, $seco ? 'crearía' : 'creado', $nombre, self::INICIO, self::FIN));
            if ($seco) {
                continue;
            }

            $componente = (new TravelComponente())
                ->setNombreInterno($nombre)
                ->setTipo(ComponenteTipoEnum::EXCURSION_POOL);
            $this->em->persist($componente);
            $servicio->addComponente($componente);

            // Los setters de la tarifa no devuelven nada: no se encadenan.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno($nombre . ' · Incluido en el día 1');
            $tarifa->setTitulo([['language' => 'es', 'content' => 'Incluido']]);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');
            $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
            $tarifa->setPrestador($proveedor);
            $tarifa->setComprador($proveedor);
            $this->em->persist($tarifa);

            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setTarifaPredeterminada($tarifa)
                ->setItinerarioContexto($plantilla)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setDia(2)
                ->setOrden(1)
                ->setHora(new \DateTimeImmutable(self::INICIO))
                ->setHoraFin(new \DateTimeImmutable(self::FIN))
                ->setHoraServicioCompleto(true));
        }

        $medioDia = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => self::MEDIO_DIA]);
        $cerrado = false;
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['itinerarioContexto' => $medioDia, 'horaServicioCompleto' => true]) as $pivote) {
            if ($pivote->getHoraFin()?->format('H:i') === self::MEDIO_DIA_FIN) {
                $io->text(sprintf('· %s — ya cierra a las %s', self::MEDIO_DIA, self::MEDIO_DIA_FIN));
                $cerrado = true;
                continue;
            }

            $io->text(sprintf('· %s — fin a las %s %s', self::MEDIO_DIA, self::MEDIO_DIA_FIN, $seco ? '(se pondría)' : 'puesto'));
            $cerrado = true;
            if (!$seco) {
                $pivote->setHoraFin(new \DateTimeImmutable(self::MEDIO_DIA_FIN));
            }
        }

        if (!$cerrado) {
            $io->error(sprintf('La plantilla «%s» no tiene promovido.', self::MEDIO_DIA));

            return Command::FAILURE;
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
