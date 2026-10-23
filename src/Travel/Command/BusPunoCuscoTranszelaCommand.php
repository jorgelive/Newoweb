<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Transzela, el segundo proveedor del bus Cusco ↔ Puno (23/10/2026).
 *
 * Jorge: «Transzela sólo tiene un servicio, está 50 soles». Se llama Inka Suite —con k, Jorge—, y
 * así se llama la tarifa: el proveedor va en `prestador`/`comprador`, no en el nombre
 * (`docs/TravelCargaDeCatalogo.md`). La de por defecto sigue siendo Cruz del Sur Evolution Regular
 * (`app:travel:bus-puno-cusco-cruz-del-sur`).
 *
 * ⚠️ Transzela SÍ sale del Terminal Terrestre (counter 112 en Cusco, C-10 en Puno); Cruz del Sur no.
 *
 * Idempotente: la organización por nombre comercial, la tarifa por nombre dentro del componente.
 */
#[AsCommand(
    name: 'app:travel:bus-puno-cusco-transzela',
    description: 'Añade Transzela (Inka Suite, S/50) al bus Cusco ↔ Puno.',
    hidden: true,
)]
final class BusPunoCuscoTranszelaCommand extends Command
{
    private const string COMPONENTE = 'Transporte Puno ↔ Cusco (ida o vuelta)';
    private const string PROVEEDOR = 'Transzela';
    private const string TARIFA = 'Bus Inka Suite';

    /** Como se cargó primero, mal escrito («Inca»): la tarifa existente se renombra. */
    private const string TARIFA_ANTES = 'Bus Inca Suite';
    private const string MONTO = '50.00';

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

        $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => self::COMPONENTE]);
        $moneda = $this->em->getRepository(MaestroMoneda::class)->find('PEN');
        if (!$componente instanceof TravelComponente || !$moneda instanceof MaestroMoneda) {
            $io->error('Falta el componente del bus Puno ↔ Cusco o la moneda PEN.');

            return Command::FAILURE;
        }

        foreach ($componente->getTarifas() as $tarifa) {
            if ($tarifa->getNombreInterno() === self::TARIFA_ANTES) {
                $io->text(sprintf('· «%s» %s «%s»', self::TARIFA_ANTES, $seco ? 'pasaría a' : 'pasa a', self::TARIFA));
                if (!$seco) {
                    $tarifa->setNombreInterno(self::TARIFA);
                    $tarifa->setTitulo([['language' => 'es', 'content' => self::TARIFA]]);
                    $tarifa->setNombreParaPrestador('Traslado Puno ↔ Cusco · ' . self::TARIFA);
                    $this->em->flush();
                }

                return Command::SUCCESS;
            }
            if ($tarifa->getNombreInterno() === self::TARIFA) {
                $io->success(sprintf('«%s» ya está (S/%s).', self::TARIFA, $tarifa->getMonto()));

                return Command::SUCCESS;
            }
        }

        $proveedor = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::PROVEEDOR]);
        $io->text(sprintf('· Proveedor %s — %s', self::PROVEEDOR, $proveedor !== null ? 'ya existe' : ($seco ? 'se crearía' : 'creado')));
        $io->text(sprintf('· Tarifa «%s» S/%s — %s', self::TARIFA, self::MONTO, $seco ? 'se crearía' : 'creada'));

        if ($seco) {
            $io->success('Simulacro: no se escribió nada.');

            return Command::SUCCESS;
        }

        if (!$proveedor instanceof TravelOrganizacion) {
            $proveedor = (new TravelOrganizacion())
                ->setNombreComercial(self::PROVEEDOR)
                ->setTitulo([['language' => 'es', 'content' => self::PROVEEDOR]])
                ->setDescripcion([['language' => 'es', 'content' => 'Empresa de buses interprovinciales. '
                    . 'Sale del Terminal Terrestre de Cusco y del de Puno.']])
                ->setVisibleParaCliente(false);
            $this->em->persist($proveedor);
        }

        // Los setters de la tarifa no devuelven nada: no se encadenan.
        $tarifa = new TravelTarifa();
        $tarifa->setComponente($componente);
        $tarifa->setNombreInterno(self::TARIFA);
        $tarifa->setTitulo([['language' => 'es', 'content' => self::TARIFA]]);
        $tarifa->setNombreParaPrestador('Traslado Puno ↔ Cusco · ' . self::TARIFA);
        $tarifa->setMoneda($moneda);
        $tarifa->setMonto(self::MONTO);
        $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
        $tarifa->setPrestador($proveedor);
        $tarifa->setComprador($proveedor);
        $this->em->persist($tarifa);

        $this->em->flush();
        $io->success('Hecho.');

        return Command::SUCCESS;
    }
}
