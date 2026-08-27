<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelLugar;
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
 *
 * # cuando el aeropuerto y la etiqueta operativa no se llaman igual
 * php bin/console app:travel:crear-vuelos-por-ruta "Lima<>Juliaca@Puno"
 * ```
 *
 * ── Los lugares ─────────────────────────────────────────────────────────────
 * Cada ruta se etiqueta con sus DOS extremos, y la etiqueta se crea si no existe. Con eso el
 * filtro por lugar de La Biblia encuentra el vuelo desde cualquiera de las dos puntas, que es como
 * se busca de verdad: «qué tengo en Cusco ese día» tiene que sacar tanto el que llega como el que
 * sale.
 *
 * El sufijo `@` separa el AEROPUERTO de la ETIQUETA cuando no coinciden: se vuela a Juliaca pero
 * se opera en Puno, y crear «Juliaca» al lado de «Puno» parte en dos el filtro de un mismo sitio.
 * El nombre del componente conserva siempre el aeropuerto real —es la ruta que se compra—.
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

        $repo       = $this->em->getRepository(TravelComponente::class);
        $repoLugar  = $this->em->getRepository(TravelLugar::class);
        $creados    = 0;
        $existen    = 0;
        $etiquetados = 0;
        /** @var array<string, TravelLugar|true> $lugares */
        $lugares    = [];

        foreach ($tramos as [$ladoOrigen, $ladoDestino]) {
            // «Juliaca@Puno»: el aeropuerto nombra la ruta, la etiqueta agrupa la operación.
            [$origen, $etiquetaOrigen]   = $this->partirExtremo($ladoOrigen);
            [$destino, $etiquetaDestino] = $this->partirExtremo($ladoDestino);
            // Nomenclatura calcada de los trenes: «Tren Ollanta Mapi». Terso y sin preposiciones,
            // porque es el nombre OPERATIVO —el que se busca en un desplegable—, no la prosa que
            // lee el cliente. Ésa va en `titulo`.
            $nombre = sprintf('Vuelo %s %s', $origen, $destino);

            $componente = $repo->findOneBy(['nombre' => $nombre]);

            if ($componente !== null) {
                $io->text(sprintf('  ya existe · %s', $nombre));
                ++$existen;
            } else {
                $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $nombre));
                ++$creados;

                if (!$simula) {
                    $componente = (new TravelComponente())
                        ->setNombre($nombre)
                        ->setTipo(ComponenteTipoEnum::VUELO)
                        // El título público, en español y sin abreviar: es lo que ve el pasajero, y
                        // de aquí salen las traducciones. La redacción imita a la que ya usan los
                        // segmentos de vuelo, para que las dos superficies no se contradigan.
                        ->setTitulo([[
                            'language' => 'es',
                            'content'  => sprintf('Vuelo desde la ciudad de %s a la ciudad de %s', $origen, $destino),
                        ]]);

                    $this->em->persist($componente);
                }
            }

            // Los dos extremos, siempre — también en los que ya existían, que es lo que hace que
            // este comando se pueda repetir para completar lo que quedó a medias.
            foreach ([$etiquetaOrigen, $etiquetaDestino] as $nombreLugar) {
                if ($simula) {
                    if (!isset($lugares[$nombreLugar]) && $repoLugar->findOneBy(['nombre' => $nombreLugar]) === null) {
                        $lugares[$nombreLugar] = true;
                        $io->text(sprintf('      + etiqueta nueva · %s', $nombreLugar));
                    }

                    continue;
                }

                $lugar = $this->asegurarLugar($nombreLugar, $lugares, $io);

                if ($componente !== null && !$componente->getLugares()->contains($lugar)) {
                    $componente->addLugar($lugar);
                    ++$etiquetados;
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf(
            '%s: %d · ya existían: %d · etiquetas puestas: %d',
            $simula ? 'Se crearían' : 'Creados',
            $creados,
            $existen,
            $etiquetados
        ));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');

            return Command::SUCCESS;
        }

        $io->success('Listo. Las traducciones las genera el listener de AutoTranslate al persistir.');

        return Command::SUCCESS;
    }

    /**
     * Separa «Juliaca@Puno» en aeropuerto y etiqueta. Sin `@`, son el mismo.
     *
     * @return array{0: string, 1: string}
     */
    private function partirExtremo(string $lado): array
    {
        if (!str_contains($lado, '@')) {
            return [$lado, $lado];
        }

        $partes = array_map('trim', explode('@', $lado, 2));

        return [$partes[0], $partes[1] !== '' ? $partes[1] : $partes[0]];
    }

    /**
     * La etiqueta, creándola si no existe. Se cachea por nombre porque una misma ciudad aparece en
     * muchas rutas y `findOneBy` no ve lo que todavía no se ha vaciado a la base.
     *
     * @param array<string, TravelLugar|true> $cache
     */
    private function asegurarLugar(string $nombre, array &$cache, SymfonyStyle $io): TravelLugar
    {
        if (($ya = $cache[$nombre] ?? null) instanceof TravelLugar) {
            return $ya;
        }

        $lugar = $this->em->getRepository(TravelLugar::class)->findOneBy(['nombre' => $nombre]);

        if ($lugar === null) {
            $lugar = (new TravelLugar())->setNombre($nombre);
            $this->em->persist($lugar);
            $io->text(sprintf('      + etiqueta nueva · %s', $nombre));
        }

        $cache[$nombre] = $lugar;

        return $lugar;
    }
}
