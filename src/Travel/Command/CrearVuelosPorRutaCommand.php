<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da de alta un componente de catálogo por RUTA de vuelo.
 *
 * ── Por qué una ruta es un componente y no un segmento ──────────────────────
 * Un vuelo **es** su ruta: «Lima → Cusco» es lo que se compra, y por eso su identidad pertenece al
 * componente. Los trenes ya están así —«Tren Ollanta Mapi», «Tren Mapi Ollanta», seis rutas—; los
 * vuelos eran la excepción, con un único «Ticket aereo» genérico al que el itinerario tenía que
 * rescatar para saber de qué vuelo se hablaba.
 *
 * ⚠️ Esto **no** se generaliza a todos los componentes de nombre genérico. Cabe un segmento «Vuelo
 * Cusco a Lima», pero no uno «Walking Sticks en Vinicunca»: los bastones son un extra colgado de
 * una excursión y se resuelven viendo lo que tienen al lado. La regla es por tipo de servicio, no
 * por si el nombre parece genérico. Ver `docs/Operacion.md`.
 *
 * ── Por qué comando y no SQL ────────────────────────────────────────────────
 * `TravelComponente::$titulo` lleva `#[AutoTranslate]`, y ese listener cuelga de
 * `prePersist`/`preUpdate`. Un `INSERT` directo se lo salta y deja la ficha **sólo en español**,
 * sin que nada lo denuncie: las siete traducciones no aparecen hasta que alguien vuelve a guardar
 * a mano. Ver la tabla «migración vs. comando» de `CLAUDE.md`.
 *
 * ── Uso ─────────────────────────────────────────────────────────────────────
 * ```
 * # una dirección
 * php bin/console app:travel:crear-vuelos-por-ruta "Lima>Cusco" --dry-run
 *
 * # ida y vuelta, que es lo normal
 * php bin/console app:travel:crear-vuelos-por-ruta "Lima<>Cusco" "Lima<>Arequipa"
 * ```
 *
 * Idempotente por `nombre`: repetirlo no duplica nada ni pisa lo que ya exista.
 */
#[AsCommand(
    name: 'app:travel:crear-vuelos-por-ruta',
    description: 'Crea componentes de vuelo por ruta («Vuelo Lima Cusco»), como ya están los trenes.',
)]
final class CrearVuelosPorRutaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'rutas',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Rutas: «Lima>Cusco» una dirección, «Lima<>Cusco» ida y vuelta.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        /** @var string[] $rutas */
        $rutas  = $input->getArgument('rutas');
        $tramos = [];

        foreach ($rutas as $ruta) {
            $ambos = str_contains($ruta, '<>');
            $lados = array_map('trim', explode($ambos ? '<>' : '>', $ruta));

            if (\count($lados) !== 2 || $lados[0] === '' || $lados[1] === '') {
                $io->error(sprintf('No entiendo la ruta «%s». Se escribe «Lima>Cusco» o «Lima<>Cusco».', $ruta));

                return Command::INVALID;
            }

            $tramos[] = [$lados[0], $lados[1]];

            if ($ambos) {
                $tramos[] = [$lados[1], $lados[0]];
            }
        }

        $repo    = $this->em->getRepository(TravelComponente::class);
        $creados = 0;
        $existen = 0;

        foreach ($tramos as [$origen, $destino]) {
            // Nomenclatura calcada de los trenes: «Tren Ollanta Mapi». Terso y sin preposiciones,
            // porque es el nombre OPERATIVO —el que se busca en un desplegable—, no la prosa que
            // lee el cliente. Ésa va en `titulo`.
            $nombre = sprintf('Vuelo %s %s', $origen, $destino);

            if ($repo->findOneBy(['nombre' => $nombre]) !== null) {
                $io->text(sprintf('  ya existe · %s', $nombre));
                ++$existen;
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $nombre));

            if ($simula) {
                ++$creados;
                continue;
            }

            $componente = (new TravelComponente())
                ->setNombre($nombre)
                ->setTipo(ComponenteTipoEnum::VUELO)
                // El título público, en español y sin abreviar: es lo que ve el pasajero, y de
                // aquí salen las traducciones. La redacción imita a la que ya usan los segmentos
                // de vuelo existentes, para que las dos superficies no se contradigan.
                ->setTitulo([[
                    'language' => 'es',
                    'content'  => sprintf('Vuelo desde la ciudad de %s a la ciudad de %s', $origen, $destino),
                ]]);

            $this->em->persist($componente);
            ++$creados;
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('%s: %d · ya existían: %d', $simula ? 'Se crearían' : 'Creados', $creados, $existen));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');

            return Command::SUCCESS;
        }

        $io->success('Listo. Las traducciones las genera el listener de AutoTranslate al persistir.');

        return Command::SUCCESS;
    }
}
