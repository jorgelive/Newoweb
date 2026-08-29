<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cierra las tarifas que la fusión bidireccional dejó partidas por sentido.
 *
 * `app:travel:fusionar-transportes-bidireccionales` conserva las dos cuando los importes no
 * coinciden, porque elegir habría sido inventar dinero. Quedan marcadas con su sentido
 * («Cruz del Sur Crucero Evolution · Ica → Lima») esperando una decisión humana. Éste la aplica.
 *
 * ## La regla: la MÁS ALTA
 *
 * Dictada por el operador el 29/08/2026 para las tarifas de bus, y el motivo importa: **son
 * referenciales**, no un precio pactado. Se usan para cotizar, y el pasajero compra su pasaje al
 * precio del día. Quedarse corto obliga a pedir la diferencia después; pasarse deja margen. Con
 * un precio pactado la regla sería la contraria, así que **no se generalice sin pensarlo**.
 *
 * ## Lo que se queda fuera
 *
 * `PENDIENTES` lista los grupos que NO se resuelven porque su diferencia no parece referencial
 * sino un error. `Auto · Paracas → Ica` a 300 contra `Ica → Paracas` a 200 es el mismo tramo con
 * 100 soles de diferencia: uno de los dos está mal, y coger el alto sería tan arbitrario como
 * coger el bajo.
 */
#[AsCommand(
    name: 'app:travel:resolver-tarifas-divergentes',
    description: 'Funde las tarifas partidas por sentido quedándose con la más alta.',
)]
final class ResolverTarifasDivergentesCommand extends Command
{
    /** El separador que puso la fusión: «vehículo · origen → destino». */
    private const MARCA = ' · ';

    /**
     * Grupos donde la regla general NO aplica, con el importe que dictó el operador.
     *
     * ⚠️ Existe porque «la más alta» vale para una tarifa REFERENCIAL y no para un error. El
     * `Auto` de Paracas↔Ica tenía 300 en un sentido y 200 en el otro: mismo tramo, cien soles de
     * diferencia. Ahí no hay margen que preservar, hay un dato mal, y el bueno era el bajo — lo
     * contrario de lo que habría hecho la regla. Por eso la excepción se escribe, no se calcula.
     *
     * @var array<string, string>
     */
    private const DECIDIDOS = [
        'Transporte Paracas ↔ Ica (ida o vuelta)|Auto' => '200.00',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué resolvería sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        /** @var array<string, list<TravelTarifa>> $grupos */
        $grupos = [];

        foreach ($this->em->getRepository(TravelTarifa::class)->findAll() as $tarifa) {
            $nombre = $tarifa->getNombreInterno() ?? '';

            if (!str_contains($nombre, self::MARCA) || !str_contains($nombre, '→')) {
                continue;
            }

            $vehiculo = trim(explode(self::MARCA, $nombre)[0]);
            $componente = $tarifa->getComponente()?->getNombreInterno() ?? '?';
            $grupos[$componente . '|' . $vehiculo][] = $tarifa;
        }

        if ($grupos === []) {
            $io->success('No quedan tarifas partidas por sentido.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d grupos partidos por sentido', count($grupos)));

        $resueltos = 0;

        foreach ($grupos as $clave => $tarifas) {
            [$componente, $vehiculo] = explode('|', $clave, 2);

            $dictado = self::DECIDIDOS[$clave] ?? null;

            if ($dictado !== null) {
                // Se ordena por CERCANÍA al importe dictado, no por tamaño: el operador nombró
                // un número, y gana la tarifa que lo lleva, sea la alta o la baja.
                usort($tarifas, static fn (TravelTarifa $a, TravelTarifa $b): int
                    => abs((float) ($a->getMonto() ?? '0') - (float) $dictado)
                    <=> abs((float) ($b->getMonto() ?? '0') - (float) $dictado));
            } else {
                // La más alta. `usort` con comparación numérica: los importes son cadenas
                // decimales y ordenarlas como texto pondría «9.00» por encima de «65.00».
                usort($tarifas, static fn (TravelTarifa $a, TravelTarifa $b): int
                    => (float) ($b->getMonto() ?? '0') <=> (float) ($a->getMonto() ?? '0'));
            }

            $gana = $tarifas[0];
            $io->text(sprintf(
                '  %s · %s → <info>%s</info>%s  (descarta %s)',
                $componente,
                $vehiculo,
                $gana->getMonto() ?? '',
                $dictado !== null ? ' <fg=cyan>(dictado)</>' : '',
                implode(', ', array_map(static fn (TravelTarifa $t): string => $t->getMonto() ?? '', array_slice($tarifas, 1))),
            ));

            ++$resueltos;

            if ($simula) {
                continue;
            }

            // El sentido desaparece del nombre: ya no hay dos, y dejarlo diría que sí.
            $gana->setNombreInterno($vehiculo);
            $gana->setTitulo([['language' => 'es', 'content' => $vehiculo]]);

            foreach (array_slice($tarifas, 1) as $descartada) {
                $this->ceder($descartada, $gana);
                $this->em->remove($descartada);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('  resueltos: %d de %d', $resueltos, count($grupos)));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * Lo que apuntaba a la descartada pasa a la que queda.
     *
     * ⚠️ Las citas de cotización se olvidaron en la primera versión de la fusión y dejaron trece
     * enlaces al vacío: no rompe nada visible, pero la línea deja de poder volver al maestro.
     */
    private function ceder(TravelTarifa $muere, TravelTarifa $sobrevive): void
    {
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['tarifaPredeterminada' => $muere]) as $rel) {
            $rel->setTarifaPredeterminada($sobrevive);
        }

        foreach ($this->em->getRepository(CotizacionCottarifa::class)->findBy(['tarifaMaestraId' => (string) $muere->getId()]) as $cita) {
            $cita->setTarifaMaestraId((string) $sobrevive->getId());
        }
    }
}
