<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La Ruta del Sol con Inka Express, y las entradas pasan a incluidas (23/10/2026).
 *
 * Del «Tarifario confidencial para agencias 2026» de Inka Express (Jorge):
 *
 *   Servicio SIC / pool, Cusco ↔ Puno      pública US$ 50 · neta agencia US$ 38 · agencia + IGV US$ 44,84
 *   No incluye: ingresos                    Andahuaylillas S/ 18 · Raqchi S/ 20 · Pukará S/ 15
 *
 * - **Inka Express** como organización, con el contacto de reservas del tarifario, y una segunda
 *   tarifa del bus con ella de prestador y comprador, a la NETA (US$ 38, sin IGV, como se cargan los
 *   montos del proveedor). Turismo Mer sigue siendo la tarifa por defecto.
 * - Las dos tarifas del bus se llaman «Compartido» de cara al cliente y llevan la empresa en el
 *   nombre interno, porque son hermanas que sólo se distinguen por quién presta.
 * - **Las entradas pasan de «No incluye» a «Incluye»**: los operadores no las incluyen, pero Jorge
 *   sí las da, como componentes aparte que suman al costo. Llevan tarifa por defecto —la de
 *   Andahuaylillas, la de extranjero—, porque un incluido sin tarifa se publicaría como opcional.
 *   Un pasajero peruano se cambia a mano.
 *
 * Fuera a propósito: el bus privado (charter de 40 asientos, US$ 850 sin IGV), que no se pidió.
 *
 * Idempotente.
 */
#[AsCommand(
    name: 'app:travel:ruta-del-sol-inka-express',
    description: 'Añade Inka Express a la Ruta del Sol y pasa las entradas a incluidas.',
    hidden: true,
)]
final class RutaDelSolInkaExpressCommand extends Command
{
    private const string BUS = 'Pool Ruta del Sol';
    private const string INKA = 'Inka Express';
    private const string MER = 'Turismo Mer';
    private const string NETA = '38.00';
    private const array PLANTILLAS = ['1D RSOL: CUZ PUN', '1D RSOL: PUN CUZ'];

    /** Entrada → la tarifa que va por defecto. */
    private const array ENTRADAS = [
        'Ingreso a Andahuaylillas' => 'Extranjero',
        'Ingreso a Raqchi' => 'General',
        'Ingreso al Museo Lítico de Pukará' => 'Ingreso al Museo Lítico de Pukará · General',
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

        $bus = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => self::BUS])
            ?? throw new \RuntimeException('Falta ' . self::BUS);
        $mer = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::MER])
            ?? throw new \RuntimeException('Falta ' . self::MER);

        // ── Inka Express ─────────────────────────────────────────────────────────────
        $inka = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::INKA]);
        if ($inka instanceof TravelOrganizacion) {
            $io->text('· Inka Express — ya existe');
        } else {
            $io->text(sprintf('· Inka Express — %s (bookings@inkaexpress.com · +51 908 849 544)', $seco ? 'se crearía' : 'creada'));
            $inka = (new TravelOrganizacion())
                ->setNombreComercial(self::INKA)
                ->setRazonSocial(self::INKA)
                ->setTelefono('+51 908 849 544')
                ->setEmail('bookings@inkaexpress.com')
                ->setUrl('https://www.inkaexpress.com/')
                ->setVisibleParaCliente(false);
            if (!$seco) {
                $this->em->persist($inka);
            }
        }

        // ── Las tarifas del bus ──────────────────────────────────────────────────────
        $deInka = null;
        foreach ($bus->getTarifas() as $tarifa) {
            if ($tarifa->getPrestador() === $mer && $tarifa->getNombreInterno() !== self::BUS . ' · Compartido · Turismo Mer') {
                $io->text('· Tarifa de Mer — lleva la empresa en el nombre interno');
                if (!$seco) {
                    $tarifa->setNombreInterno(self::BUS . ' · Compartido · Turismo Mer');
                }
            }
            if ($inka->getId() !== null && $tarifa->getPrestador() === $inka) {
                $deInka = $tarifa;
            }
        }

        if ($deInka === null) {
            $io->text(sprintf('· Tarifa de Inka Express — %s, US$ %s neto', $seco ? 'se crearía' : 'creada', self::NETA));
            if (!$seco) {
                $tarifa = new TravelTarifa();
                $tarifa->setComponente($bus);
                $tarifa->setNombreInterno(self::BUS . ' · Compartido · Inka Express');
                $tarifa->setTitulo([['language' => 'es', 'content' => 'Compartido']]);
                $tarifa->setMoneda($this->em->find(MaestroMoneda::class, 'USD') ?? throw new \RuntimeException('Falta USD'));
                $tarifa->setMonto(self::NETA);
                $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
                $tarifa->setPrestador($inka);
                $tarifa->setComprador($inka);
                $tarifa->setNombreParaPrestador('Ruta del Sol Cusco ↔ Puno (SIC)');
                $this->em->persist($tarifa);
            }
        } else {
            $io->text('· Tarifa de Inka Express — ya existe');
        }

        // ── Las entradas, a «Incluye» con su tarifa por defecto ──────────────────────
        foreach (self::PLANTILLAS as $slug) {
            $plantilla = $this->em->getRepository(TravelItinerario::class)->findOneBy(['slug' => $slug])
                ?? throw new \RuntimeException('Falta la plantilla ' . $slug);

            foreach (self::ENTRADAS as $nombre => $tarifaNombre) {
                $entrada = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre])
                    ?? throw new \RuntimeException('Falta ' . $nombre);
                $pivote = $this->em->getRepository(TravelSegmentoComponente::class)->findOneBy([
                    'componente' => $entrada, 'itinerarioContexto' => $plantilla,
                ]) ?? throw new \RuntimeException(sprintf('Falta %s en %s', $nombre, $slug));

                $porDefecto = null;
                foreach ($entrada->getTarifas() as $t) {
                    if ($t->getNombreInterno() === $tarifaNombre) {
                        $porDefecto = $t;
                    }
                }
                if ($porDefecto === null) {
                    throw new \RuntimeException(sprintf('%s no tiene la tarifa «%s»', $nombre, $tarifaNombre));
                }

                if ($pivote->getModo() === ComponenteModoEnum::INCLUIDO && $pivote->getTarifaPredeterminada() === $porDefecto) {
                    continue;
                }

                $io->text(sprintf('· %s · %s — incluida, por defecto «%s» %s', $slug, $nombre, $tarifaNombre, $porDefecto->getMonto()));
                if (!$seco) {
                    $pivote->setModo(ComponenteModoEnum::INCLUIDO)->setTarifaPredeterminada($porDefecto);
                }
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
