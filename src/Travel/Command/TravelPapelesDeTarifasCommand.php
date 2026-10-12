<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prestador y comprador de las tarifas del catálogo, por componente (12/10/2026).
 *
 * La cotización copia los dos papeles de la TARIFA al elegirla (`sembrarPapelesEnLinea()`), y casi
 * ninguna los tenía: en la de Elizabeth y Familia (25JVEH) sólo los trenes los traían, y Consettur,
 * Junela u Orientour había que teclearlos en cada línea. Las organizaciones sí estaban en el
 * maestro; faltaba el enlace. Esto lo pone, con lo que Jorge va confirmando.
 *
 * Una fila por decisión: qué componentes —por nombre interno EXACTO, no buscando una palabra en el
 * nombre: «Guiado Vinicunca» contiene «Vinicunca» y no es de la comunidad— y qué papeles. Un papel
 * que no se dice no se toca: el comprador depende a veces del tour y se deja para la cotización.
 *
 * Una organización que falte sólo se crea si la fila lo dice (`crear`): un genérico como la
 * comunidad campesina sí; un nombre mal escrito no debe convertirse en una empresa nueva.
 *
 * ⚠️ Apaga la traducción de las tarifas antes de guardar, como `app:travel:comprador-trenes`:
 * poner un id no cambia ningún texto. Idempotente: sólo toca lo que difiere.
 *
 * Lo que NO está aquí, a propósito: los transportes («todavía nada», Jorge) y los guiados (cada
 * tarifa es de un guía y aún no está dicho cuál).
 */
#[AsCommand(
    name: 'app:travel:papeles-tarifas',
    description: 'Pone prestador y comprador a las tarifas del catálogo según la tabla acordada.',
)]
final class TravelPapelesDeTarifasCommand extends Command
{
    /**
     * Todas las claves en todas las filas: `null` en un papel = no se toca. `tarifas` limita la fila a
     * las tarifas con esos nombres internos exactos; `null` = todas las del componente.
     *
     * @var list<array{componentes: list<string>, tarifas: ?list<string>, prestador: ?string, comprador: ?string, crear: bool, motivo: string}>
     */
    private const array FILAS = [
        [
            'componentes' => ['Ingreso a Vinicunca', 'Ingreso a Humantay', 'Ingreso a Quelccaya', 'Ingreso a Palcoyo'],
            'tarifas' => null,
            'prestador' => 'Comunidad Campesina de la zona',
            'comprador' => null,
            'crear' => true,
            'motivo' => 'Los cobra la comunidad del lugar: un prestador genérico para los cuatro. El comprador depende del tour.',
        ],
        [
            'componentes' => [
                'Boleto Turistico Integral - BTC',
                'Boleto Turistico Parcial Sacsaywaman - BTPS',
                'Boleto Turistico Parcial Valle - BTPV',
                'Boleto Turistico Parcial Valle Sur - BTPVS',
            ],
            'tarifas' => null,
            'prestador' => 'Cosituc',
            'comprador' => 'Orientour',
            'crear' => false,
            'motivo' => 'Lo emite Cosituc y «normalmente es Orientour el que compra».',
        ],
        [
            'componentes' => ['Consettur Subida', 'Consettur Bajada'],
            'tarifas' => null,
            'prestador' => 'Consettur',
            'comprador' => 'OpenPeru Tickets',
            'crear' => false,
            'motivo' => 'Los boletos del bus los saca OpenPeru Tickets, como los del tren (confirmado el 12/10/2026).',
        ],
        [
            'componentes' => ['Ingreso a Machu Picchu'],
            'tarifas' => null,
            'prestador' => 'Ministerio de Cultura',
            'comprador' => 'OpenPeru Tickets',
            'crear' => false,
            'motivo' => 'Lo emite el Ministerio de Cultura y lo saca OpenPeru Tickets.',
        ],
        [
            'componentes' => ['Ingreso a Koricancha'],
            'tarifas' => null,
            'prestador' => 'Arzobispado del Cusco',
            'comprador' => 'Orientour',
            'crear' => false,
            'motivo' => 'Lo cobra el Arzobispado y lo compra Orientour con su tour.',
        ],
        [
            // Sólo la tarifa que se llama como la empresa: «Pool combinada» tiene también tarifas
            // de Americana o Machupicchu Turístico, que no son Orientour.
            'componentes' => ['Pool combinada', 'Pool Valle Sagrado', 'Pool Valle Sur'],
            'tarifas' => ['Orientour'],
            'prestador' => 'Orientour',
            'comprador' => null,
            'crear' => false,
            'motivo' => 'La tarifa ES de Orientour: su nombre lo dice, y ahora lo dice el prestador.',
        ],
        [
            // ⚠️ «Orientur», con errata: es Orientour. No se renombra la tarifa aquí —eso es texto y
            // se traduce—; sólo se le pone el prestador que su nombre quería decir.
            'componentes' => ['Pool Maras Moray', 'Pool Maras Moray Ollanta', 'Pool Super Valle'],
            'tarifas' => ['Orientur'],
            'prestador' => 'Orientour',
            'comprador' => null,
            'crear' => false,
            'motivo' => 'Misma regla que la fila de arriba, con el nombre mal escrito.',
        ],
        [
            // Sólo comprador: el restaurante varía (Jorge, 12/10/2026). Mismo criterio que ya tenía
            // «Buffet Mejorado Orientour».
            'componentes' => ['Almuerzo en Urubamba'],
            'tarifas' => ['Buffet Regular Pool'],
            'prestador' => null,
            'comprador' => 'Orientour',
            'crear' => false,
            'motivo' => 'Lo encarga Orientour con su pool; el restaurante cambia.',
        ],
        [
            'componentes' => ['Pool Vinicunca', 'Pool Humantay', 'Pool Palcoyo'],
            'tarifas' => ['Junela'],
            'prestador' => 'Junela',
            'comprador' => null,
            'crear' => false,
            'motivo' => 'La tarifa ES de Junela.',
        ],
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
        $io = new SymfonyStyle($input, $output);
        $simulacro = (bool) $input->getOption('dry-run');
        $filas = [];
        $cambios = 0;

        foreach ($this->filas() as $fila) {
            // Qué papeles toca esta fila, decidido una vez: `null` en la tabla = no se toca.
            $nombrePrestador = $fila['prestador'];
            $nombreComprador = $fila['comprador'];
            $tocaPrestador = $nombrePrestador !== null;
            $tocaComprador = $nombreComprador !== null;

            $prestador = $tocaPrestador ? $this->organizacion($nombrePrestador, $fila['crear'], $simulacro, $io) : null;
            $comprador = $tocaComprador ? $this->organizacion($nombreComprador, $fila['crear'], $simulacro, $io) : null;

            if ($prestador === false || $comprador === false) {
                return Command::FAILURE;
            }

            foreach ($fila['componentes'] as $nombre) {
                $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);

                if (!$componente instanceof TravelComponente) {
                    $io->warning(sprintf('No existe el componente «%s»: se salta.', $nombre));
                    continue;
                }

                /** @var list<TravelTarifa> $tarifas */
                $tarifas = $this->em->getRepository(TravelTarifa::class)->findBy(['componente' => $componente]);

                if ($fila['tarifas'] !== null) {
                    $soloEstas = $fila['tarifas'];
                    $tarifas = array_values(array_filter(
                        $tarifas,
                        static fn (TravelTarifa $t): bool => in_array($t->getNombreInterno(), $soloEstas, true),
                    ));
                }
                $tocadas = 0;

                foreach ($tarifas as $tarifa) {
                    $poner = [];

                    // `null` = aún no existe (simulacro de una que se va a crear): cuenta como cambio.
                    if ($tocaPrestador && ($prestador === null || $tarifa->getPrestador() !== $prestador)) {
                        $poner['prestador'] = $prestador;
                    }
                    if ($tocaComprador && ($comprador === null || $tarifa->getComprador() !== $comprador)) {
                        $poner['comprador'] = $comprador;
                    }

                    if ($poner === []) {
                        continue;
                    }

                    ++$tocadas;

                    if ($simulacro) {
                        continue;
                    }

                    if (array_key_exists('prestador', $poner) && $poner['prestador'] instanceof TravelOrganizacion) {
                        $tarifa->setPrestador($poner['prestador']);
                    }
                    if (array_key_exists('comprador', $poner) && $poner['comprador'] instanceof TravelOrganizacion) {
                        $tarifa->setComprador($poner['comprador']);
                    }
                    // Ver la cabecera: un id no es texto, no hay nada que traducir.
                    $tarifa->setEjecutarTraduccion(false);
                }

                $cambios += $tocadas;
                $filas[] = [$nombre, $nombrePrestador ?? '—', $nombreComprador ?? '—', count($tarifas), $tocadas];
            }
        }

        $io->table(['Componente', 'Prestador', 'Comprador', 'Tarifas', 'Cambian'], $filas);

        if ($simulacro) {
            $io->warning(sprintf('%d tarifa(s) cambiarían. Nada escrito.', $cambios));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d tarifa(s) actualizada(s).', $cambios));

        return Command::SUCCESS;
    }

    /**
     * La tabla, con su tipo de fila: leída directamente, el análisis la estrecha a sus valores
     * literales y da por decidido lo que en el bucle varía de una fila a otra.
     *
     * @return list<array{componentes: list<string>, tarifas: ?list<string>, prestador: ?string, comprador: ?string, crear: bool, motivo: string}>
     */
    private function filas(): array
    {
        return self::FILAS;
    }

    /**
     * La organización por su nombre comercial. `null` si falta y se va a crear en un simulacro;
     * `false` si falta y no se puede crear (error ya dicho).
     */
    private function organizacion(string $nombre, bool $crear, bool $simulacro, SymfonyStyle $io): TravelOrganizacion|false|null
    {
        $org = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => $nombre]);

        if ($org instanceof TravelOrganizacion) {
            return $org;
        }

        if (!$crear) {
            $io->error(sprintf('No existe la organización «%s» en el maestro. Revisa el nombre.', $nombre));

            return false;
        }

        $io->note(sprintf('Alta de «%s» en el catálogo%s.', $nombre, $simulacro ? ' (simulacro)' : ''));

        if ($simulacro) {
            return null;
        }

        $org = (new TravelOrganizacion())
            ->setNombreComercial($nombre)
            ->setRazonSocial($nombre)
            ->setTitulo([['language' => 'es', 'content' => $nombre]]);
        $this->em->persist($org);

        return $org;
    }
}
