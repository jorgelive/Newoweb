<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Completa el título público de los traslados de Punta Cana.
 *
 * Decían «Del aeropuerto de Punta Cana al alojamiento», y los de Perú dicen la ciudad en **los
 * dos** extremos: «Transporte desde el Aeropuerto de Lima al hotel en Lima». En un itinerario que
 * cruza países, «al alojamiento» a secas obliga al huésped a deducir dónde está durmiendo esa
 * noche a partir del párrafo anterior.
 *
 * ⚠️ **Va por comando y no por SQL**: `TravelSegmento::$titulo` lleva `#[AutoTranslate]`. Se
 * escribe **sólo el español** y el listener rellena los otros seis. Un `UPDATE` directo dejaría
 * seis traducciones diciendo lo de antes, que es peor que no tocarlo.
 *
 * De paso quita la dirección del nombre de las tarifas del traslado ya fusionado. Nacieron como
 * «Auto · Del aeropuerto de Punta Cana al alojamiento» porque el componente era direccional; al
 * unificarlo quedaron ocho tarifas donde bastan cuatro, y la coletilla ya no distingue nada.
 *
 * ⚠️ **No se generaliza a todo « · »**, por más que tiente. Ese separador también une prestador y
 * servicio —«Occidental Caribe - Punta Cana · Almuerzo buffet en resort»— y ahí sí distingue: una
 * regla que cortara por el punto destrozaría esos nombres sin que nadie lo notara hasta leer una
 * orden. Se corrige el caso concreto, no el símbolo.
 */
#[AsCommand(
    name: 'app:travel:completar-traslados-punta-cana',
    description: 'Pone la ciudad en los dos extremos del título de los traslados de Punta Cana.',
)]
final class CompletarTrasladosPuntaCanaCommand extends Command
{
    /** @var array<string, string> slug → título en español */
    private const TITULOS = [
        'TRANS-APT_PUJ-HOTEL_PUJ' => 'Transporte desde el aeropuerto de Punta Cana al alojamiento en Punta Cana',
        'TRANS-HTL_PUJ-AEROPUERTO_PUJ' => 'Transporte desde el alojamiento en Punta Cana al aeropuerto de Punta Cana',
    ];

    /**
     * El traslado unificado y las dos coletillas de dirección que se le quitan a sus tarifas.
     *
     * @var list<string>
     */
    private const SUFIJOS_DE_DIRECCION = [
        ' · Del aeropuerto de Punta Cana al alojamiento',
        ' · Del alojamiento al aeropuerto de Punta Cana',
    ];

    private const COMPONENTE_UNIFICADO = 'Transporte Aeropuerto Punta Cana ↔ Hotel Punta Cana (ida o vuelta)';

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
        $simula = (bool) $input->getOption('dry-run');
        $repo = $this->em->getRepository(TravelSegmento::class);

        foreach (self::TITULOS as $slug => $titulo) {
            $segmento = $repo->findOneBy(['slug' => $slug]);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $slug));
                continue;
            }

            $actual = '';

            foreach ($segmento->getTitulo() as $bloque) {
                if (($bloque['language'] ?? '') === 'es') {
                    $actual = (string) ($bloque['content'] ?? '');
                }
            }

            if ($actual === $titulo) {
                $io->text(sprintf('  ya está · %s', $slug));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'cambiaría' : 'cambiado ', $slug));
            $io->text(sprintf('      %s', $actual));
            $io->text(sprintf('    → %s', $titulo));

            if (!$simula) {
                // Sólo el español: los otros seis los pone el listener.
                $segmento->setTitulo([['language' => 'es', 'content' => $titulo]]);
            }
        }

        $this->aplanarTarifas($io, $simula);

        if (!$simula) {
            $this->em->flush();
        }

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        $io->text('');
        $io->text('Recuerda correr después «app:travel:limpiar-tarifas-repetidas», que es quien');
        $io->text('junta las copias que quedan con el mismo nombre, precio y capacidad.');

        return Command::SUCCESS;
    }

    /**
     * Le quita a las tarifas del traslado unificado la dirección que llevaban en el nombre.
     *
     * Idempotente: si ya no queda ningún sufijo, no hace nada y lo dice.
     */
    private function aplanarTarifas(SymfonyStyle $io, bool $simula): void
    {
        $componente = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombreInterno' => self::COMPONENTE_UNIFICADO]);

        if ($componente === null) {
            $io->text(sprintf('  no existe · %s', self::COMPONENTE_UNIFICADO));

            return;
        }

        $tocadas = 0;

        foreach ($componente->getTarifas() as $tarifa) {
            $nombre = (string) $tarifa->getNombreInterno();

            foreach (self::SUFIJOS_DE_DIRECCION as $sufijo) {
                if (!str_ends_with($nombre, $sufijo)) {
                    continue;
                }

                $vehiculo = trim(substr($nombre, 0, -mb_strlen($sufijo)));

                $io->text(sprintf('  %s · %s → %s', $simula ? 'cambiaría' : 'cambiada ', $nombre, $vehiculo));
                ++$tocadas;

                if (!$simula) {
                    $tarifa->setNombreInterno($vehiculo);
                    // El título también, y sólo en español: lleva #[AutoTranslate] igual que el
                    // del segmento.
                    $tarifa->setTitulo([['language' => 'es', 'content' => $vehiculo]]);
                }

                break;
            }
        }

        if ($tocadas === 0) {
            $io->text('  ya está · ninguna tarifa lleva la dirección en el nombre');
        }
    }
}
