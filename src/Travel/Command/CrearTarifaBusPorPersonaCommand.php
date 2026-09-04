<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
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
 * Añade el bus privado cobrado POR PERSONA a los dos traslados de aeropuerto.
 *
 * ## Por qué una tarifa nueva y no rellenar la que ya está
 *
 * Los dos componentes ya tienen un «Bus» a 0.00 con `costoPorGrupo = true`: ése es el vehículo
 * entero, y su precio se negocia por vehículo. Éste cobra por cabeza, así que **no es el mismo
 * precio sin rellenar: es otro modelo de cobro**. Meterlo encima del existente habría hecho que
 * un bus de 45 plazas costara 8 dólares en total.
 *
 * `modalidad = privado` y `costoPorGrupo = false` no se contradicen: privado describe el
 * vehículo —no se comparte con otros grupos—, y `costoPorGrupo` describe cómo se multiplica el
 * importe. Un bus en exclusiva facturado por pasajero es exactamente eso.
 *
 * ⚠️ **Va por comando y no por migración.** `TravelTarifa::$titulo` lleva `#[AutoTranslate]`, y un
 * `INSERT` en SQL se salta el listener: la tarifa se quedaría sólo en español. Es la regla de
 * `docs/TravelCargaDeCatalogo.md` §6, y por eso este comando tarda: escribe `es` y el listener
 * rellena los otros seis idiomas.
 *
 * ⚠️ **Los setters de `TravelTarifa` no se encadenan todos** —`setNombreInterno`, `setTitulo`,
 * `setMoneda` y `setCapacidadMaxima` devuelven `void`—, así que aquí va una llamada por línea.
 * Es la trampa de §7 del mismo doc.
 */
#[AsCommand(
    name: 'app:travel:crear-tarifa-bus-por-persona',
    description: 'Añade el bus privado por persona (8 USD) a los traslados de Lima y Punta Cana.'
)]
final class CrearTarifaBusPorPersonaCommand extends Command
{
    private const MONEDA = 'USD';
    private const BASE = 'Bus privado por persona';
    private const PLAZAS = 45;

    /**
     * Qué tarifas nacen en cada componente.
     *
     * ⚠️ **Lima se desdobla en Día/Noche y Punta Cana no**, y no es un descuido: ese desdoble
     * existe en UN solo componente de todo el catálogo —`Aeropuerto Lima ↔ Miraflores`— y ahí lo
     * llevan sus ocho tarifas con precio. Punta Cana no distingue franja en ninguna de las suyas,
     * así que darle una tarifa «Día» sin «Noche» sería inventarle un eje que no usa.
     *
     * El recargo nocturno de Lima es **+20% exacto** en los cuatro pares que tienen precio
     * (70→84, 110→132, 200→240, 280→336), así que 8.00 → 9.60. Si el bus por persona se cobra
     * plano de noche, iguala los dos importes y ya está.
     *
     * @var array<string, array{prestador: string, tarifas: array<string, string>}>
     */
    private const DESTINOS = [
        'Transporte Aeropuerto Lima ↔ Miraflores (ida o vuelta)' => [
            'prestador' => 'Traslado Aeropuerto Lima ↔ Miraflores',
            'tarifas' => ['Día' => '8.00', 'Noche' => '9.60'],
        ],
        'Transporte Aeropuerto Punta Cana ↔ Hotel Punta Cana (ida o vuelta)' => [
            'prestador' => 'Traslado Aeropuerto Punta Cana ↔ Hotel Punta Cana',
            'tarifas' => ['' => '8.00'],
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $moneda = $this->em->getRepository(MaestroMoneda::class)->find(self::MONEDA);

        if ($moneda === null) {
            $io->error(sprintf('No existe la moneda «%s» en el maestro.', self::MONEDA));

            return Command::FAILURE;
        }

        $componentes = $this->em->getRepository(TravelComponente::class);
        $creadas = 0;

        foreach (self::DESTINOS as $nombreComponente => $def) {
            $componente = $componentes->findOneBy(['nombreInterno' => $nombreComponente]);

            if ($componente === null) {
                $io->warning(sprintf('no está el componente · %s', $nombreComponente));

                continue;
            }

            foreach ($def['tarifas'] as $franja => $monto) {
                $nombreTarifa = trim(self::BASE . ' ' . $franja);

                // La tarifa no tiene clave natural (§4bis), así que la idempotencia es ésta y
                // sólo ésta: el par (componente, nombreInterno). Sin ella, dos pasadas dan dos
                // tarifas iguales y hace falta `app:travel:limpiar-tarifas-repetidas`.
                $yaEsta = $this->em->getRepository(TravelTarifa::class)->findOneBy([
                    'componente' => $componente,
                    'nombreInterno' => $nombreTarifa,
                ]);

                if ($yaEsta !== null) {
                    $io->text(sprintf('  ya existe · %s · %s', $nombreTarifa, $nombreComponente));

                    continue;
                }

                $io->text(sprintf(
                    '  %s · %s %s · %s · %s',
                    $simula ? 'crearía' : 'creada ',
                    self::MONEDA,
                    $monto,
                    $nombreTarifa,
                    $nombreComponente
                ));

                ++$creadas;

                if ($simula) {
                    continue;
                }

                $tarifa = new TravelTarifa();
                $tarifa->setNombreInterno($nombreTarifa);
                $tarifa->setTitulo([['language' => 'es', 'content' => $nombreTarifa]]);
                $tarifa->setMoneda($moneda);
                $tarifa->setMonto($monto);
                $tarifa->setModalidad(TarifaModalidadEnum::PRIVADO);
                $tarifa->setCostoPorGrupo(false);
                $tarifa->setCapacidadMaxima(self::PLAZAS);
                $tarifa->setNombreParaPrestador($def['prestador'] . ' · ' . $nombreTarifa);

                // `addTarifa()` mantiene las DOS puntas; `setComponente()` a secas deja
                // `getTarifas()` vacío y quien lea la colección justo después no la ve
                // (§«la tarifa por defecto salió nula»).
                $componente->addTarifa($tarifa);

                $this->em->persist($tarifa);
            }
        }

        if (!$simula && $creadas > 0) {
            $io->text('');
            $io->text('Traduciendo a los siete idiomas (esto tarda)…');
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s %d tarifa(s) «%s».',
            $simula ? 'Crearía' : 'Creadas',
            $creadas,
            self::BASE
        ));

        return Command::SUCCESS;
    }
}
