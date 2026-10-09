<?php

declare(strict_types=1);

namespace App\Travel\Command;

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
 * Las tarifas de cuatrimotos llevan el NOMBRE DE PILA del dueño, y eso no es un prestador.
 *
 * «John Individual», «Katy ATV Doble», «Mayra Doble»: ocho tarifas en tres componentes, todas
 * con `prestador_id` a null. El dato está —se sabe a quién se le compra— pero está escrito en
 * el único sitio donde no sirve para nada:
 *
 *   · la **Orden de Servicio** lee `prestador` y `comprador`, no el nombre de la tarifa;
 *   · comparar dos proveedores exige leer prosa en vez de agrupar por ficha;
 *   · y el día que John deje la empresa, el catálogo sigue diciendo John.
 *
 * Este comando no cambia un nombre propio por otro: mueve el dato al campo que lo representa y
 * deja en el nombre de la tarifa sólo lo que de verdad la distingue de sus hermanas —la
 * modalidad de la máquina—, que es lo que pide la regla de que el componente identifica la fila.
 *
 * ⚠️ **Sólo toca las personas que están en `self::PERSONAS`.** Las demás se informan y se dejan
 * intactas: inventarles una empresa sería peor que no tener el dato. Añadir una es una línea.
 *
 * ⚠️ **No pisa una tarifa que ya tenga prestador.** Si alguien lo asignó a mano, gana lo de la
 * base: el catálogo no sabe si la constante de este comando está más al día.
 *
 * El prestador nace OCULTO al cliente (`visibleParaCliente = false`): son operadores a los que
 * se les compra, no marcas que se enseñen. Si algún día uno debe salir con sus fotos, se
 * enciende la bandera y se le crean sus servicios de prestador.
 */
#[AsCommand(
    name: 'app:travel:asignar-prestadores-cuatrimotos',
    description: 'Mueve el proveedor del nombre de la tarifa al campo prestador, en cuatrimotos.',
    hidden: true,
)]
final class AsignarPrestadoresCuatrimotosCommand extends Command
{
    /**
     * Nombre de pila que encabeza la tarifa → la empresa que hay detrás.
     *
     * @var array<string, array{empresa: string, descripcion: string}>
     */
    private const PERSONAS = [
        'John' => [
            'empresa' => 'Top Andean Travel',
            'descripcion' => 'Operador de cuatrimotos y tirolina en la meseta de Maras, '
                . 'con base en la comunidad de Cjhua.',
        ],
    ];

    /**
     * Cómo queda el nombre de la tarifa al quitarle la persona.
     *
     * Se compara sobre lo que queda tras el nombre de pila, en minúsculas. Lo que no case se
     * deja tal cual, sin la persona: es preferible un nombre escueto a uno inventado.
     *
     * @var array<string, string>
     */
    private const NOMBRES = [
        'individual' => 'ATV individual',
        'doble' => 'ATV doble (compartida) · por pasajero',
        'atv simple' => 'ATV individual',
        'atv doble' => 'ATV doble (compartida) · por pasajero',
        'individual menú andino' => 'ATV individual · con menú andino',
        'doble menú andino' => 'ATV doble (compartida) · con menú andino, por pasajero',
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

        $io->title('Prestadores de las tarifas de cuatrimotos');

        /** @var list<TravelTarifa> $tarifas */
        $tarifas = $this->em->getRepository(TravelTarifa::class)->createQueryBuilder('t')
            ->join('t.componente', 'c')->addSelect('c')
            ->where('LOWER(c.nombreInterno) LIKE :atv')
            ->setParameter('atv', '%cuatrimoto%')
            ->getQuery()->getResult();

        $tocadas = 0;
        $pendientes = [];

        foreach ($tarifas as $tarifa) {
            $nombre = (string) $tarifa->getNombreInterno();
            $persona = $this->personaDe($nombre);

            if ($persona === null) {
                continue;
            }

            if ($tarifa->getPrestador() !== null) {
                $io->text(sprintf('  respeta  · %-34s ya tiene prestador', $nombre));
                continue;
            }

            if (!isset(self::PERSONAS[$persona])) {
                $pendientes[$persona][] = $nombre;
                continue;
            }

            $empresa = self::PERSONAS[$persona]['empresa'];
            $nuevo = $this->nombreSinPersona($nombre, $persona);

            ++$tocadas;
            $io->text(sprintf(
                '  %s · %-34s → %-44s prestador: %s',
                $simula ? 'cambiaría' : 'cambiada ',
                $nombre,
                $nuevo,
                $empresa,
            ));

            if ($simula) {
                continue;
            }

            $organizacion = $this->organizacion($empresa, self::PERSONAS[$persona]['descripcion']);

            $tarifa->setNombreInterno($nuevo);
            $tarifa->setTitulo([['language' => 'es', 'content' => $nuevo]]);
            $tarifa->setPrestador($organizacion);
            $tarifa->setComprador($organizacion);
        }

        if (!$simula) {
            $this->em->flush();
        }

        if ($pendientes !== []) {
            $io->section('Sin empresa conocida — intactas');

            foreach ($pendientes as $persona => $nombres) {
                $io->text(sprintf('  %-8s %s', $persona, implode(' · ', $nombres)));
            }

            $io->text('');
            $io->text('Añádelas a self::PERSONAS cuando sepas de qué empresa son.');
        }

        $io->newLine();
        $io->success(sprintf('%s %d tarifa(s).', $simula ? 'Se cambiarían' : 'Cambiadas', $tocadas));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * El nombre de pila que encabeza la tarifa, si lo hay.
     */
    private function personaDe(string $nombre): ?string
    {
        $primera = strtok($nombre, ' ');

        if ($primera === false) {
            return null;
        }

        // Una tarifa que empieza por una palabra capitalizada que no es una modalidad ni una
        // máquina: eso es una persona. No se busca contra una lista de nombres porque la lista
        // crecería con cada proveedor nuevo y el fallo sería silencioso.
        return preg_match('/^[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+$/u', $primera) === 1
            && !in_array(mb_strtolower($primera), ['atv', 'individual', 'doble', 'simple', 'pool', 'privado'], true)
                ? $primera
                : null;
    }

    private function nombreSinPersona(string $nombre, string $persona): string
    {
        $resto = trim(mb_substr($nombre, mb_strlen($persona)));

        return self::NOMBRES[mb_strtolower($resto)] ?? $resto;
    }

    private function organizacion(string $empresa, string $descripcion): TravelOrganizacion
    {
        $organizacion = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => $empresa]);

        if ($organizacion !== null) {
            return $organizacion;
        }

        $organizacion = (new TravelOrganizacion())
            ->setNombreComercial($empresa)
            ->setTitulo([['language' => 'es', 'content' => $empresa]])
            ->setDescripcion([['language' => 'es', 'content' => $descripcion]])
            ->setVisibleParaCliente(false);

        $this->em->persist($organizacion);
        $this->em->flush();

        return $organizacion;
    }
}
