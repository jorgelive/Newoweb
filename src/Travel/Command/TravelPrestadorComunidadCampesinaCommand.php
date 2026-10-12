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
 * «Comunidad Campesina de la zona» como prestador de los ingresos de montaña (12/10/2026).
 *
 * Los ingresos a Vinicunca, Humantay, Quelccaya y Palcoyo los cobra la comunidad campesina del
 * lugar, que no es una empresa con nombre propio en cada caso. Jorge pidió un prestador GENÉRICO
 * para los cuatro: así la tarifa ya trae quién presta y no hay que teclearlo en cada cotización
 * (lo destapó la de Elizabeth y Familia, 25JVEH).
 *
 * Sólo el PRESTADOR: quién compra el ingreso depende del tour —en 25JVEH lo compraba Junela con su
 * pool— y eso no es una regla del componente.
 *
 * Los componentes se identifican por su nombre interno EXACTO, no por buscar el lugar dentro del
 * nombre: «Guiado Vinicunca» o «Pool Vinicunca» también lo contienen y no son de la comunidad.
 *
 * ⚠️ Apaga la traducción de las tarifas antes de guardar, como `app:travel:comprador-trenes`: poner
 * un id de empresa no cambia ningún texto. La organización nueva sí se traduce: su título es texto.
 *
 * Idempotente: sólo toca las tarifas que no tienen ya este prestador.
 */
#[AsCommand(
    name: 'app:travel:prestador-comunidad-campesina',
    description: 'Pone «Comunidad Campesina de la zona» como prestador de los ingresos de Vinicunca, Humantay, Quelccaya y Palcoyo.',
)]
final class TravelPrestadorComunidadCampesinaCommand extends Command
{
    private const string PRESTADOR = 'Comunidad Campesina de la zona';

    /** Nombre interno exacto de cada componente de ingreso. */
    private const array COMPONENTES = [
        'Ingreso a Vinicunca',
        'Ingreso a Humantay',
        'Ingreso a Quelccaya',
        'Ingreso a Palcoyo',
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

        $comunidad = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::PRESTADOR]);

        if (!$comunidad instanceof TravelOrganizacion) {
            $io->note(sprintf('Alta de «%s» en el catálogo%s.', self::PRESTADOR, $simulacro ? ' (simulacro)' : ''));

            if (!$simulacro) {
                $comunidad = (new TravelOrganizacion())
                    ->setNombreComercial(self::PRESTADOR)
                    ->setRazonSocial(self::PRESTADOR)
                    ->setTitulo([['language' => 'es', 'content' => self::PRESTADOR]]);
                $this->em->persist($comunidad);
            }
        }

        $filas = [];
        $puestos = 0;

        foreach (self::COMPONENTES as $nombre) {
            $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);

            if (!$componente instanceof TravelComponente) {
                $io->warning(sprintf('No existe el componente «%s»: se salta.', $nombre));
                continue;
            }

            /** @var list<TravelTarifa> $tarifas */
            $tarifas = $this->em->getRepository(TravelTarifa::class)->findBy(['componente' => $componente]);
            $cambian = 0;

            foreach ($tarifas as $tarifa) {
                if ($comunidad !== null && $tarifa->getPrestador() === $comunidad) {
                    continue;
                }

                ++$cambian;

                if (!$simulacro && $comunidad !== null) {
                    $tarifa->setPrestador($comunidad);
                    // Ver la cabecera: un id no es texto, no hay nada que traducir.
                    $tarifa->setEjecutarTraduccion(false);
                }
            }

            $puestos += $cambian;
            $filas[] = [$nombre, count($tarifas), $cambian];
        }

        $io->table(['Componente', 'Tarifas', 'Se les pone el prestador'], $filas);

        if ($simulacro) {
            $io->warning(sprintf('%d tarifa(s) recibirían a «%s». Nada escrito.', $puestos, self::PRESTADOR));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d tarifa(s) con «%s» como prestador.', $puestos, self::PRESTADOR));

        return Command::SUCCESS;
    }
}
