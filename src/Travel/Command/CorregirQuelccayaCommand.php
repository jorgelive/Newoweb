<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\Cotizacion;
use App\Travel\Entity\TravelComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * «Quelcaya» → «Quelccaya» en los campos i18n, que son los que NO pueden ir por migración.
 *
 * El glaciar se escribe con dos ces y el catálogo tenía las dos grafías a la vez: el componente
 * «Ingreso a Quel**cc**aya» junto a «Pool Quel**c**aya». Con una letra de diferencia la línea de
 * la orden parecía repetir el mismo texto y **ninguna regla de silencio podía verlo** — para una
 * comparación son dos cadenas distintas. Ver `docs/Operacion.md` §13.bis.
 *
 * ## Por qué un comando y no la migración que va al lado
 *
 * Porque `TravelComponente::$titulo` y `Cotizacion::$titulo` llevan `#[AutoTranslate]`, y un
 * `UPDATE` en SQL **se salta el listener**: dejaría el español corregido y siete traducciones
 * diciendo «Quelcaya». Es exactamente el reparto de CLAUDE.md, «Qué entra por migración y qué
 * tiene que entrar por comando». Todo lo demás —snapshots congelados, el código del servicio, los
 * JSON derivados— no pasa por ningún listener y va en `Version20261005010000`.
 *
 * ## Por qué se corrigen los SIETE idiomas a mano y se apaga el listener
 *
 * Es un nombre propio: se escribe igual en los siete. Dejar que el listener retraduzca cambiaría
 * la redacción entera de cada título por una errata de ortografía, y costaría siete llamadas por
 * fila. Así que se sustituye en todos, `setEjecutarTraduccion(false)` impide que el servicio
 * trabaje, y después `app:traduccion:sellar-hash` vuelve a cuadrar los `origenHash` — si no, la
 * próxima edición del español creería que las traducciones están desfasadas.
 *
 * ⚠️ **Lo que este comando NO arregla, y conviene mirar:** el `titulo` del componente tiene las
 * traducciones de OTRO sitio —el inglés dice «Entrance ticket to the Rainbow Mountain», que es
 * Vinicunca, no el glaciar—. Eso no es una errata, es contenido equivocado, y corregirlo es
 * decidir una redacción nueva: no se hace de paso en un comando de ortografía.
 */
#[AsCommand(
    name: 'app:travel:corregir-quelccaya',
    description: 'Corrige «Quelcaya» → «Quelccaya» en los campos i18n (los que no pueden ir por SQL).',
    hidden: true,
)]
final class CorregirQuelccayaCommand extends Command
{
    private const MAL = 'Quelcaya';
    private const BIEN = 'Quelccaya';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué cambiaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $tocadas = 0;

        // Las dos listas se concatenan en vez de recorrer un array de clases: no comparten
        // interfaz, y con la unión PHPStan no puede saber cuál de las dos tiene delante.
        $entidades = [
            ...$this->em->getRepository(TravelComponente::class)->findAll(),
            ...$this->em->getRepository(Cotizacion::class)->findAll(),
        ];

        {
            foreach ($entidades as $entidad) {
                $titulo = $entidad->getTitulo();
                $nuevo = $this->corregir($titulo);

                if ($nuevo === $titulo) {
                    continue;
                }

                ++$tocadas;
                $io->text(sprintf('  %s #%s', (new \ReflectionClass($entidad))->getShortName(), (string) $entidad->getId()));

                foreach ($nuevo as $i => $fila) {
                    $antes = $titulo[$i]['content'] ?? '';
                    $ahora = $fila['content'] ?? '';
                    if ($antes !== $ahora) {
                        $io->text(sprintf('      %-3s %-45s → %s', $fila['language'] ?? '??', (string) $antes, (string) $ahora));
                    }
                }

                if ($simula) {
                    continue;
                }

                // Nombre propio: ya va corregido en los siete. Que el listener no retraduzca.
                $entidad->setEjecutarTraduccion(false);
                $entidad->setTitulo($nuevo);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->success(sprintf('%d entidad(es) %s.', $tocadas, $simula ? 'cambiarían' : 'corregidas'));

        if (!$simula && $tocadas > 0) {
            $io->note('Ahora: php bin/console app:traduccion:sellar-hash — para recuadrar los origenHash.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $i18n
     *
     * @return list<array{language?: string, content?: string|null}>
     */
    private function corregir(array $i18n): array
    {
        foreach ($i18n as $i => $fila) {
            $contenido = $fila['content'] ?? null;

            if (\is_string($contenido) && str_contains($contenido, self::MAL)) {
                $i18n[$i]['content'] = str_replace(self::MAL, self::BIEN, $contenido);
            }
        }

        return $i18n;
    }
}
