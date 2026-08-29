<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCotcomponente;
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
 * Cambia el TIPO de un componente del catálogo.
 *
 * Suena cosmético y no lo es: el tipo decide quién va en grande para el proveedor
 * ({@see ComponenteTipoEnum::mandaElSegmento()}), dónde empieza y acaba el servicio
 * ({@see ComponenteTipoEnum::puntosDeServicio()}), si parte el día ({@see ComponenteTipoEnum::esSalto()})
 * y —el motivo de este comando— **si la UI pide hora**
 * ({@see ComponenteTipoEnum::sinHorario()}).
 *
 * ## El caso que lo pidió
 *
 * Las comidas del aeropuerto de Lima estaban como `ALIMENTACION_HORARIO_VAR`, que es el tipo de
 * «almuerzo en algún momento del recorrido»: no pide hora. Pero un desayuno a las 07:00 antes de
 * un vuelo **sí la tiene**, y sin ella el servicio entero cae al escalón de «sin horario» y se
 * ordena detrás de todo lo que sí la lleva. La hora estaba en el catálogo —07:00, 12:30, 19:00—
 * y no contaba.
 *
 * ⚠️ **La alternativa que se descartó**: hacer que `ALIMENTACION_HORARIO_VAR` exigiera hora. Ese
 * tipo existe justo para la comida que no la tiene, y cambiarlo se habría llevado por delante los
 * almuerzos de excursión, que son la mayoría. El tipo estaba bien definido; lo que estaba mal era
 * a cuál pertenecían estas tres.
 *
 * ⚠️ **Las cotizaciones ya hechas NO se retipan.** `CotizacionCotcomponente::$tipo` se congela al
 * cotizar y dice cómo se vendió: un servicio que se ordenó sin hora salió al cliente sin hora, y
 * cambiarlo a posteriori reordenaría un itinerario ya enviado. El comando avisa de cuántas hay.
 */
#[AsCommand(
    name: 'app:travel:retipar-componente',
    description: 'Cambia el tipo de un componente del catálogo, con aviso de a qué afecta.',
)]
final class RetiparComponenteCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('nombre', InputArgument::REQUIRED, 'Nombre interno del componente.')
            ->addArgument('tipo', InputArgument::REQUIRED, 'Tipo nuevo, p. ej. `alimentacion_fijo`.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        $nombre = (string) $input->getArgument('nombre');
        $nuevo = ComponenteTipoEnum::tryFrom((string) $input->getArgument('tipo'));

        if ($nuevo === null) {
            $io->error(sprintf('Tipo desconocido. Los válidos son: %s', implode(', ', array_column(ComponenteTipoEnum::cases(), 'value'))));

            return Command::FAILURE;
        }

        $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $nombre]);

        if ($componente === null) {
            $io->error(sprintf('No existe «%s».', $nombre));

            return Command::FAILURE;
        }

        $viejo = $componente->getTipo();

        if ($viejo === $nuevo) {
            $io->text(sprintf('  ya es %s · %s', $nuevo->value, $nombre));

            return Command::SUCCESS;
        }

        $io->section($nombre);
        $io->text(sprintf('  %s → %s', $viejo->value, $nuevo->value));

        // Lo que cambia de verdad, dicho antes de escribir: el tipo mueve cuatro comportamientos
        // y ninguno se ve en la ficha.
        $io->listing([
            sprintf('pide hora: %s → %s', $this->si(!$viejo->sinHorario()), $this->si(!$nuevo->sinHorario())),
            sprintf('manda el segmento: %s → %s', $this->si($viejo->mandaElSegmento()), $this->si($nuevo->mandaElSegmento())),
            sprintf('oculta el segmento: %s → %s', $this->si($viejo->ocultaElSegmento()), $this->si($nuevo->ocultaElSegmento())),
            sprintf('puntos: %s → %s', $viejo->puntosDeServicio()->value, $nuevo->puntosDeServicio()->value),
        ]);

        $congeladas = count($this->em->getRepository(CotizacionCotcomponente::class)
            ->findBy(['componenteMaestroId' => (string) $componente->getId()]));

        if ($congeladas > 0) {
            $io->warning(sprintf(
                '%d líneas de cotización lo citan y NO se retipan: su tipo dice cómo se vendió, y cambiarlo reordenaría un itinerario ya enviado.',
                $congeladas,
            ));
        }

        if (!$simula) {
            $componente->setTipo($nuevo);
            $this->em->flush();
            $io->success('Retipado.');
        } else {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function si(bool $valor): string
    {
        return $valor ? 'sí' : 'no';
    }
}
