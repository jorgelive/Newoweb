<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Unifica las dos grafías de la clase «The 360°» de IncaRail.
 *
 * `IR 360` estaba en las rutas de Poroy e `IR T360` en las de Ollantaytambo: **no eran
 * duplicados**, era la misma clase escrita distinta según qué día se cargó cada componente. Y los
 * títulos públicos habían derivado igual, unos con `°` y otros sin. Nadie se equivocó: quien los
 * dio de alta veía una tarifa cada vez, no el conjunto.
 *
 * Se queda `IR 360`, que es la que se parece al nombre comercial. Y el título público pasa a
 * llevar el `°` siempre — es lo que lee el pasajero, y media docena de fichas diciéndolo de dos
 * formas es la clase de detalle que hace dudar de todo lo demás.
 *
 * ⚠️ **Por comando y no por SQL**: `$titulo` lleva `#[AutoTranslate]`, así que un `UPDATE` dejaría
 * el español corregido y las otras seis lenguas con la grafía vieja. `$nombreInterno` es un
 * `string` pelado y ahí daría igual, pero van juntos.
 *
 * ⚠️ Ninguna de estas tarifas se usa todavía en una cotización (comprobado: 0 usos), así que no
 * hay snapshots congelados que queden descolgados del maestro. Si los hubiera, **no se tocarían**:
 * un snapshot es lo que se vendió.
 *
 * Idempotente: sólo cambia lo que no está ya unificado.
 */
#[AsCommand(
    name: 'app:travel:unificar-360-incarail',
    description: 'Deja una sola grafía para la clase The 360° de IncaRail, en nombre y título.',
)]
final class UnificarTarifasIncaRail360Command extends Command
{
    /** Prefijo viejo → prefijo bueno, en el nombre interno. */
    private const RENOMBRES = ['IR T360' => 'IR 360'];

    /** El título público correcto por sufijo del nombre. */
    private const TITULOS = [
        'Adulto' => 'Tren IncaRail The 360°',
        'Niño'   => 'Tren IncaRail The 360° - Tarifa niño',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $nombres = $titulos = 0;

        foreach ($this->em->getRepository(TravelTarifa::class)->findAll() as $tarifa) {
            $nombre = (string) $tarifa->getNombreInterno();

            if (!str_starts_with($nombre, 'IR 360') && !str_starts_with($nombre, 'IR T360')) {
                continue;
            }

            $componente = $tarifa->getComponente()?->getNombreInterno() ?? '?';

            foreach (self::RENOMBRES as $viejo => $bueno) {
                if (str_starts_with($nombre, $viejo)) {
                    $nuevo = $bueno . substr($nombre, \strlen($viejo));
                    $io->text(sprintf('  %s · %-18s → %-16s (%s)', $simula ? 'renombraría' : 'renombrada ', $nombre, $nuevo, $componente));

                    if (!$simula) {
                        $tarifa->setNombreInterno($nuevo);
                    }

                    $nombre = $nuevo;
                    ++$nombres;
                }
            }

            // El título, por el sufijo del nombre ya unificado.
            foreach (self::TITULOS as $sufijo => $correcto) {
                if (!str_ends_with($nombre, $sufijo)) {
                    continue;
                }

                if ($this->textoEspanol($tarifa->getTitulo()) === $correcto) {
                    break;
                }

                $io->text(sprintf('  %s · %-18s título → «%s»', $simula ? 'corregiría ' : 'corregido  ', $nombre, $correcto));

                if (!$simula) {
                    $tarifa->setTitulo([['language' => 'es', 'content' => $correcto]]);
                }

                ++$titulos;
                break;
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('Nombres unificados: %d · títulos corregidos: %d', $nombres, $titulos));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');
        }

        return Command::SUCCESS;
    }

    /** @param array<int, array{language?: string, content?: string}> $i18n */
    private function textoEspanol(array $i18n): ?string
    {
        foreach ($i18n as $item) {
            if (($item['language'] ?? '') === 'es') {
                return trim((string) ($item['content'] ?? ''));
            }
        }

        return null;
    }
}
