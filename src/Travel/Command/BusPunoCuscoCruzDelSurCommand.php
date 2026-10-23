<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
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
 * El bus Cusco ↔ Puno pasa a las tarifas reales de Cruz del Sur (23/10/2026).
 *
 * El componente tenía «Bus estándar» (S/60) y «Bus Superior» (S/80), de un tarifario que Jorge da
 * por desactualizado, sin prestador ni comprador y sin ninguna por defecto. Él usa la salida de las
 * 22:00, y en esa ruta Cruz del Sur vende sólo dos servicios, Evolution Regular y Evolution VIP; el
 * precio es el de su web el 23/10/2026 («precio aproximado»):
 *
 * ```
 * Bus estándar   S/60   →  Bus Evolution Regular   S/55   por defecto
 * Bus Superior   S/80   →  Bus Evolution VIP       S/75
 * ```
 *
 * Se reescriben las dos filas y no se crean otras: ninguna cotización las usaba, y `travel_tarifa`
 * no tiene clave natural (dos más serían cuatro). El proveedor va en `prestador`/`comprador`, no
 * en el nombre (`docs/TravelCargaDeCatalogo.md`).
 *
 * Y la hora: el bus de vuelta Puno → Cusco no tenía ninguna; sale a las 22:00, como la ida.
 *
 * Transzela, el otro proveedor de Jorge, entra cuando haya precio.
 */
#[AsCommand(
    name: 'app:travel:bus-puno-cusco-cruz-del-sur',
    description: 'Pone las tarifas Evolution de Cruz del Sur al bus Cusco ↔ Puno y la hora del regreso.',
    hidden: true,
)]
final class BusPunoCuscoCruzDelSurCommand extends Command
{
    private const string COMPONENTE = 'Transporte Puno ↔ Cusco (ida o vuelta)';
    private const string PROVEEDOR = 'Cruz del Sur';

    /** Nombre actual → [nombre nuevo, servicio que se le pide al proveedor, monto]. */
    private const array TARIFAS = [
        'Bus estándar' => ['Bus Evolution Regular', 'Traslado Puno ↔ Cusco · Bus Evolution Regular', '55.00'],
        'Bus Superior' => ['Bus Evolution VIP', 'Traslado Puno ↔ Cusco · Bus Evolution VIP', '75.00'],
    ];

    private const string POR_DEFECTO = 'Bus Evolution Regular';
    private const array SEGMENTOS = ['TRANS_BUS-CUZ-PUN', 'TRANS_BUS-PUN-CUZ'];
    private const string HORA = '22:00';

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
        $proveedor = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::PROVEEDOR]);
        if (!$componente instanceof TravelComponente || !$proveedor instanceof TravelOrganizacion) {
            $io->error('Falta el componente del bus Puno ↔ Cusco o la organización Cruz del Sur.');

            return Command::FAILURE;
        }

        $porDefecto = null;
        foreach ($componente->getTarifas() as $tarifa) {
            $nombre = $tarifa->getNombreInterno();
            if ($nombre === self::POR_DEFECTO) {
                $porDefecto = $tarifa;
            }
            if (!is_string($nombre) || !isset(self::TARIFAS[$nombre])) {
                continue;
            }

            [$nuevo, $paraPrestador, $monto] = self::TARIFAS[$nombre];
            $io->text(sprintf('· %s S/%s → %s S/%s, %s', $nombre, $tarifa->getMonto(), $nuevo, $monto, self::PROVEEDOR));
            if ($nuevo === self::POR_DEFECTO) {
                $porDefecto = $tarifa;
            }
            if ($seco) {
                continue;
            }

            $tarifa->setNombreInterno($nuevo);
            $tarifa->setTitulo([['language' => 'es', 'content' => $nuevo]]);
            $tarifa->setNombreParaPrestador($paraPrestador);
            $tarifa->setMonto($monto);
            $tarifa->setModalidad(TarifaModalidadEnum::COMPARTIDO);
            $tarifa->setPrestador($proveedor);
            $tarifa->setComprador($proveedor);
        }

        if (!$porDefecto instanceof TravelTarifa) {
            $io->error(sprintf('No encuentro la tarifa que va por defecto («%s»).', self::POR_DEFECTO));

            return Command::FAILURE;
        }

        foreach (self::SEGMENTOS as $slug) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);
            $pivote = $this->em->getRepository(TravelSegmentoComponente::class)
                ->findOneBy(['segmento' => $segmento, 'componente' => $componente, 'itinerarioContexto' => null]);
            if (!$pivote instanceof TravelSegmentoComponente) {
                $io->error(sprintf('«%s» no tiene el bus enlazado.', $slug));

                return Command::FAILURE;
            }

            $hora = $pivote->getHora()?->format('H:i');
            $io->text(sprintf('· %s — por defecto %s, hora %s', $slug, self::POR_DEFECTO, $hora ?? self::HORA . ' (nueva)'));
            if (!$seco) {
                $pivote->setTarifaPredeterminada($porDefecto);
                if ($hora === null) {
                    $pivote->setHora(new \DateTimeImmutable(self::HORA));
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
