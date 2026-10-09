<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El retorno de Ollantaytambo cuando la van es compartida: no deja en el hotel.
 *
 * ── Por qué un segmento aparte y no una nota ────────────────────────────────
 *
 * `Transporte Cusco ↔ Ollanta (ida o vuelta)` tiene ocho tarifas y se parten en dos grupos que
 * **terminan en sitios distintos**:
 *
 *   privado      Van 73 · Master 99 · Sprinter 120 · Auto 150 · Bus 160   → al hotel de cada uno
 *   compartido   Sprinter o Master Pool 15 · Van (paquete externo) 0      → a un punto céntrico
 *
 * Una van con doce pasajeros de nueve hoteles no puede hacer nueve paradas, así que deja a todos
 * en la plaza San Francisco. Es la misma regla que ya está escrita en
 * {@see \App\Travel\Enum\ComponenteTipoEnum::esCompartido()} —lo privado devuelve al hotel, lo
 * compartido al centro— sólo que aquí no la decide el tipo del componente sino la tarifa.
 *
 * Y el extremo de un servicio lo guarda el SEGMENTO, no la tarifa. De ahí que hagan falta dos:
 * el que ya existía termina en `ALOJAMIENTO` y éste en un punto fijo.
 *
 * ⚠️ **La asimetría es real: a la IDA el pool sí recoge en el alojamiento.** Sólo el retorno
 * deja en un punto común. Tiene su lógica —al salir el vehículo tiene toda la mañana y las
 * recogidas se encadenan; al volver llega de noche y con el grupo entero a bordo— pero no se
 * deduce, así que queda escrito. `TRANS_DIRECT_SAL-MAPI-CUZ_OLL` empieza en `ALOJAMIENTO` y
 * vale para las dos modalidades: **no hace falta un gemelo de salida**, y crearlo por simetría
 * sería inventar una parada que no existe.
 *
 * ⚠️ **No entra en ninguna plantilla, y es a propósito.** La de dos días con traslado directo
 * sirve a las dos modalidades; cuál toca lo decide la tarifa que se elija al cotizar. Meter los
 * dos segmentos inyectaría ambos y meter sólo uno mentiría la mitad de las veces. Vive en el
 * pool de `TRF_CUZ` y se cambia por el otro cuando se vende la compartida — igual que el punto
 * intermedio de los bimodales, que tampoco se puede fijar en el catálogo.
 */
#[AsCommand(
    name: 'app:travel:crear-retorno-centro-cusco',
    description: 'Crea el retorno de Ollantaytambo al centro de Cusco, para la van compartida.',
    hidden: true,
)]
final class CrearRetornoCentroCuscoCommand extends Command
{
    private const SERVICIO = 'TRF_CUZ';
    private const COMPONENTE = 'Transporte Cusco ↔ Ollanta (ida o vuelta)';
    private const TARIFA_POR_DEFECTO = 'Sprinter o Master Pool';

    private const PUNTO = [
        'nombre' => 'Plaza San Francisco de Cusco',
        'direccion' => 'Plaza San Francisco, Cusco',
    ];

    private const PUNTO_ORIGEN = 'Estación de Ollantaytambo';

    private const SEGMENTO = [
        'slug' => 'TRANS_DIRECT_RET-MAPI-OLL_CENTRO',
        'nombre' => 'Traslado de Ollantaytambo al centro de Cusco (servicio compartido)',
        'titulo' => 'Retorno al centro de Cusco',
        'contenido' => 'A su llegada a la estación de Ollantaytambo les espera la movilidad para '
            . 'volver a Cusco. Al ser un servicio compartido, el vehículo deja a todo el grupo en '
            . 'la plaza San Francisco, en el centro, y desde ahí cada quien sigue a su hotel.',
        'hora' => '18:20',
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
        $simula = (bool) $input->getOption('dry-run');

        $io->title('Retorno al centro de Cusco (van compartida)');

        $componente = $this->em->getRepository(TravelComponente::class)
            ->findOneBy(['nombreInterno' => self::COMPONENTE]);

        if ($componente === null) {
            $io->error(sprintf('No existe «%s».', self::COMPONENTE));

            return Command::FAILURE;
        }

        $origen = $this->em->getRepository(TravelPunto::class)
            ->findOneBy(['nombre' => self::PUNTO_ORIGEN]);

        if ($origen === null) {
            $io->error(sprintf('No existe el punto «%s».', self::PUNTO_ORIGEN));

            return Command::FAILURE;
        }

        $servicio = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO]);

        if ($servicio === null) {
            $io->error(sprintf('No existe el servicio %s.', self::SERVICIO));

            return Command::FAILURE;
        }

        $io->section('Punto');
        $destino = $this->em->getRepository(TravelPunto::class)
            ->findOneBy(['nombre' => self::PUNTO['nombre']]);

        if ($destino !== null) {
            $io->text(sprintf('  ya existe · %s', self::PUNTO['nombre']));
        } else {
            $io->text(sprintf('  %s · %s — %s', $simula ? 'crearía' : 'creado ', self::PUNTO['nombre'], self::PUNTO['direccion']));

            if (!$simula) {
                $destino = (new TravelPunto())
                    ->setNombre(self::PUNTO['nombre'])
                    ->setDireccion(self::PUNTO['direccion']);
                $this->em->persist($destino);
                $this->em->flush();
            }
        }

        $io->section('Segmento');
        $existente = $this->em->getRepository(TravelSegmento::class)
            ->findOneBy(['slug' => self::SEGMENTO['slug']]);

        if ($existente !== null) {
            $io->text(sprintf('  ya existe · %s', self::SEGMENTO['slug']));
            $io->newLine();
            $io->success('Nada que hacer.');

            return Command::SUCCESS;
        }

        // La tarifa compartida de 15 va por defecto: es para lo que existe este segmento. La otra
        // compartida —la del paquete externo, a 0— se elige a mano cuando toque.
        $predeterminada = null;

        foreach ($componente->getTarifas() as $tarifa) {
            if ($tarifa->getNombreInterno() === self::TARIFA_POR_DEFECTO) {
                $predeterminada = $tarifa;
                break;
            }
        }

        $io->text(sprintf(
            '  %s · %-34s %s → %s   tarifa %s',
            $simula ? 'crearía' : 'creado ',
            self::SEGMENTO['slug'],
            self::PUNTO_ORIGEN,
            self::PUNTO['nombre'],
            $predeterminada instanceof TravelTarifa ? self::TARIFA_POR_DEFECTO : '⚠ sin tarifa por defecto',
        ));

        if (!$simula) {
            $segmento = (new TravelSegmento())
                ->setSlug(self::SEGMENTO['slug'])
                ->setNombreInterno(self::SEGMENTO['nombre'])
                ->setTitulo([['language' => 'es', 'content' => self::SEGMENTO['titulo']]])
                ->setContenido([['language' => 'es', 'content' => self::SEGMENTO['contenido']]])
                ->setInicioModo(PuntoModoEnum::FIJO)
                ->setInicioPunto($origen)
                ->setFinModo(PuntoModoEnum::FIJO)
                ->setFinPunto($destino);

            $this->em->persist($segmento);
            $servicio->addSegmento($segmento);
            $servicio->addComponente($componente);

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($predeterminada)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setDia(1)
                    ->setOrden(1)
                    ->setHora(new \DateTimeImmutable(self::SEGMENTO['hora'])),
            );

            $this->em->flush();
        }

        $io->newLine();
        $io->success($simula ? 'Se crearía 1 segmento.' : 'Creado 1 segmento.');

        $io->note([
            'No entra en ninguna plantilla: cuál de los dos retornos toca lo decide la tarifa que',
            'se elija al cotizar, y la plantilla sirve a las dos. Está en el pool de TRF_CUZ.',
        ]);

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
