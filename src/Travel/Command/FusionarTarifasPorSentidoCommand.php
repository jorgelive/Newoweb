<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * La duplicación por sentido, esta vez DENTRO de la tarifa.
 *
 * `Transporte Valle Sagrado ↔ Ollanta` llevaba veintiuna tarifas, y casi la mitad eran pares:
 * «Master **hasta** sector Aranwa» y «Master **desde** sector Aranwa», mismo precio, misma
 * capacidad. En un componente que ya es bidireccional, esas dos palabras no distinguen nada —
 * la dirección la pone el segmento— y sólo consiguen que el operador tenga que elegir entre dos
 * filas idénticas.
 *
 * ⚠️ **Lo que SÍ distingue en este componente es el SECTOR**: Urubamba 55, Aranwa 60, Pisac 120.
 * Eso es distancia real y se conserva entero. La diferencia entre un eje verdadero y uno falso es
 * justo lo que hay que mirar antes de fusionar nada — aquí conviven los dos en el mismo nombre.
 *
 * ## Reglas
 *
 * - Sólo actúa en componentes **bidireccionales** (`↔`). En uno direccional «desde» y «hasta»
 *   podrían significar algo.
 * - Capacidad **a la menor**, como en el resto del refactor.
 * - Si los importes difieren, **no fusiona**: lo reporta. Elegir sería inventar dinero.
 * - Lo que se borra cede antes sus relaciones y sus citas de cotización.
 */
#[AsCommand(
    name: 'app:travel:fusionar-tarifas-por-sentido',
    description: 'Funde las tarifas que sólo se diferencian en «desde»/«hasta» dentro de un componente bidireccional.',
)]
final class FusionarTarifasPorSentidoCommand extends Command
{
    /** @var list<string> */
    private const PALABRAS_DE_SENTIDO = ['desde', 'hasta'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué fusionaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        /** @var list<TravelComponente> $componentes */
        $componentes = $this->em->getRepository(TravelComponente::class)
            ->findBy(['tipo' => ComponenteTipoEnum::TRANSPORTE]);

        $fusionadas = 0;
        $discrepancias = [];

        foreach ($componentes as $componente) {
            $nombreComponente = $componente->getNombreInterno() ?? '';

            if (!str_contains($nombreComponente, '↔')) {
                continue;
            }

            /** @var array<string, list<TravelTarifa>> $grupos */
            $grupos = [];

            foreach ($componente->getTarifas() as $tarifa) {
                $grupos[$this->sinSentido($tarifa->getNombreInterno() ?? '')][] = $tarifa;
            }

            $cabecera = false;

            foreach ($grupos as $clave => $tarifas) {
                if (count($tarifas) < 2) {
                    continue;
                }

                if (!$cabecera) {
                    $io->section($nombreComponente);
                    $cabecera = true;
                }

                $importes = array_unique(array_map(
                    static fn (TravelTarifa $t): string => sprintf('%s %s', $t->getMonto() ?? '', $t->getMoneda()?->getId() ?? ''),
                    $tarifas,
                ));

                if (count($importes) > 1) {
                    $discrepancias[] = sprintf('%s · %s — %s', $nombreComponente, $clave, implode(' vs ', $importes));
                    $io->text(sprintf('  <fg=red>importe distinto</> · %s — %s', $clave, implode(' vs ', $importes)));
                    continue;
                }

                // A la menor, y el nulo no cuenta como menor: es «sin medir».
                $capacidades = array_filter(array_map(
                    static fn (TravelTarifa $t): ?int => $t->getCapacidadMaxima(),
                    $tarifas,
                ), static fn (?int $c): bool => $c !== null);

                $menor = $capacidades === [] ? null : min($capacidades);

                $gana = $tarifas[0];
                $io->text(sprintf(
                    '  funde · %-45s → %s  (cap %s)',
                    implode(' + ', array_map(static fn (TravelTarifa $t): string => $t->getNombreInterno() ?? '', $tarifas)),
                    $clave,
                    $menor ?? 'sin medir',
                ));

                ++$fusionadas;

                if ($simula) {
                    continue;
                }

                $gana->setNombreInterno($clave);
                $gana->setTitulo([['language' => 'es', 'content' => $clave]]);
                $gana->setCapacidadMaxima($menor);

                foreach (array_slice($tarifas, 1) as $sobra) {
                    $this->ceder($sobra, $gana);
                    $this->em->remove($sobra);
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('  grupos fundidos: %d', $fusionadas));

        if ($discrepancias !== []) {
            $io->section(sprintf('%d con importe distinto — se dejan', count($discrepancias)));
            $io->listing($discrepancias);
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * El nombre sin la palabra de sentido: «Master hasta sector Aranwa» → «Master sector Aranwa».
     *
     * ⚠️ Sólo se quita la palabra suelta, no como parte de otra: un `str_replace` de «desde»
     * mordería «Desdentado» y cualquier nombre que la contenga. Por eso va con límites de palabra.
     */
    private function sinSentido(string $nombre): string
    {
        $patron = '/\b(' . implode('|', self::PALABRAS_DE_SENTIDO) . ')\b/iu';
        $limpio = preg_replace($patron, '', $nombre) ?? $nombre;

        return trim(preg_replace('/\s{2,}/', ' ', $limpio) ?? $limpio);
    }

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
