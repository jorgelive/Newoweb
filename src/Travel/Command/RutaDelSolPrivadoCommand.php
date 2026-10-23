<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelComponenteItem;
use App\Travel\Entity\TravelItemDiccionario;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\ItemModoEnum;
use App\Travel\Enum\TarifaCalculoEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La Ruta del Sol en bus PRIVADO de Inka Express, y su almuerzo aparte (23/10/2026).
 *
 * Del tarifario confidencial 2026: «Servicios privados (charter) — bus exclusivo, 40 asientos»,
 * Cusco ↔ Puno a US$ 850 sin IGV (1003 con IGV). A diferencia del compartido, **no incluye el
 * almuerzo**: buffet andino a S/ 35 por pasajero. Jorge: cargar la neta.
 *
 * - **«Privado Ruta del Sol (bus de 40 asientos)»**, tipo privada, tarifa GRUPAL —el precio es del
 *   bus, se reparte entre los pasajeros— con capacidad máxima 40.
 * - **«Almuerzo buffet andino Ruta del Sol»**, S/ 35 por pasajero, también de Inka Express.
 *
 * Los dos van al pool del servicio y a NINGUNA plantilla: las plantillas son del compartido, que
 * es lo que se vende normalmente. Para un grupo privado se cambia el bus en la cotización y se
 * añade el almuerzo —ponerlos en la plantilla duplicaría bus y almuerzo en cada propuesta—.
 *
 * Idempotente por nombre interno.
 */
#[AsCommand(
    name: 'app:travel:ruta-del-sol-privado',
    description: 'Añade el bus privado de la Ruta del Sol (Inka Express) y su almuerzo aparte.',
    hidden: true,
)]
final class RutaDelSolPrivadoCommand extends Command
{
    private const string SERVICIO = 'RUTA_SOL';
    private const string INKA = 'Inka Express';
    private const string PRIVADO = 'Privado Ruta del Sol (bus de 40 asientos)';
    private const string ALMUERZO = 'Almuerzo buffet andino Ruta del Sol';

    /** Lo que incluye el chárter según el tarifario: lo del compartido menos el almuerzo. */
    private const array ITEMS = [
        'Transporte en Bus con A/C', 'Guia Profesional', 'Snacks', 'Tanque de Oxígeno',
        'Wifi a bordo (solo en lugares con señal de datos) y cargador de celular',
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

        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO])
            ?? throw new \RuntimeException('Falta el servicio ' . self::SERVICIO);
        $inka = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::INKA])
            ?? throw new \RuntimeException('Falta ' . self::INKA);

        $privado = $this->componente(self::PRIVADO, ComponenteTipoEnum::EXCURSION_PRIVADA, null, [
            'monto' => '850.00', 'moneda' => 'USD', 'titulo' => 'Bus privado', 'calculo' => TarifaCalculoEnum::GRUPAL,
            'modalidad' => TarifaModalidadEnum::PRIVADO, 'max' => 40, 'paraPrestador' => 'Ruta del Sol Cusco ↔ Puno · charter 40 asientos',
        ], $inka, $seco, $io);

        $almuerzo = $this->componente(self::ALMUERZO, ComponenteTipoEnum::ALIMENTACION_HORARIO_VAR, 'Almuerzo buffet andino', [
            'monto' => '35.00', 'moneda' => 'PEN', 'titulo' => 'Por pasajero', 'calculo' => TarifaCalculoEnum::INDIVIDUAL,
            'modalidad' => null, 'max' => null, 'paraPrestador' => 'Almuerzo buffet andino · Ruta del Sol privado',
        ], $inka, $seco, $io);

        if (!$seco) {
            $servicio->addComponente($privado);
            $servicio->addComponente($almuerzo);
            $this->em->flush();
        }

        // Ítems del chárter, en su pasada.
        foreach (self::ITEMS as $orden => $nombre) {
            $termino = $this->em->getRepository(TravelItemDiccionario::class)->findOneBy(['nombreInterno' => $nombre])
                ?? throw new \RuntimeException('Falta el término «' . $nombre . '»');
            if ($privado->getId() !== null
                && $this->em->getRepository(TravelComponenteItem::class)->findOneBy(['componente' => $privado, 'diccionario' => $termino]) !== null) {
                continue;
            }
            $io->text(sprintf('  ítem · %s', $nombre));
            if (!$seco) {
                $this->em->persist((new TravelComponenteItem())
                    ->setComponente($privado)->setDiccionario($termino)
                    ->setModo(ItemModoEnum::INCLUIDO)->setOrden($orden + 1));
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }

    /**
     * @param array{monto: string, moneda: string, titulo: string, calculo: TarifaCalculoEnum,
     *              modalidad: ?TarifaModalidadEnum, max: ?int, paraPrestador: string} $t
     */
    private function componente(string $nombre, ComponenteTipoEnum $tipo, ?string $titulo, array $t,
        TravelOrganizacion $inka, bool $seco, SymfonyStyle $io): TravelComponente
    {
        $existente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);
        if ($existente instanceof TravelComponente) {
            $io->text(sprintf('· %s — ya existe', $nombre));

            return $existente;
        }

        $io->text(sprintf('· %s — %s, %s %s %s', $nombre, $seco ? 'se crearía' : 'creado', $t['moneda'], $t['monto'], $t['calculo']->value));

        // Un privado SIN título público, como los pools: el bloque lo titula el segmento.
        $componente = (new TravelComponente())->setNombreInterno($nombre)->setTipo($tipo);
        if ($titulo !== null) {
            $componente->setTitulo([['language' => 'es', 'content' => $titulo]]);
        }

        $tarifa = new TravelTarifa();
        $tarifa->setComponente($componente);
        $tarifa->setNombreInterno($nombre . ' · ' . $t['titulo']);
        $tarifa->setTitulo([['language' => 'es', 'content' => $t['titulo']]]);
        $tarifa->setMoneda($this->em->find(MaestroMoneda::class, $t['moneda']) ?? throw new \RuntimeException('Falta ' . $t['moneda']));
        $tarifa->setMonto($t['monto']);
        $tarifa->setCalculo($t['calculo']);
        $tarifa->setModalidad($t['modalidad']);
        if ($t['max'] !== null) {
            $tarifa->setCapacidadMaxima($t['max']);
        }
        $tarifa->setPrestador($inka);
        $tarifa->setComprador($inka);
        $tarifa->setNombreParaPrestador($t['paraPrestador']);

        if (!$seco) {
            $this->em->persist($componente);
            $this->em->persist($tarifa);
        }

        return $componente;
    }
}
