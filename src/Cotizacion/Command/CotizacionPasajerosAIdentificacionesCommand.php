<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copia `tipodocumento` + `numerodocumento` del pasajero a su primera identificación.
 *
 * Las columnas viejas admitían **un** documento y no tenían vencimiento. Este comando no inventa
 * la fecha —no existía dónde guardarla—, así que lo copiado nace **sin comprobar**, que es lo que
 * de verdad es: nadie sabe cuándo caduca ese pasaporte hasta que alguien lo mire.
 *
 * Idempotente por la unicidad `(pasajero, tipo)`: volver a correrlo no duplica ni pisa. Si el
 * pasajero ya tiene una identificación de ese tipo, se salta — el dato de la fila nueva es más
 * reciente que el de la columna vieja.
 */
#[AsCommand(
    name: 'app:cotizacion:pasajeros-a-identificaciones',
    description: 'Pasa el documento único del pasajero a la tabla de identificaciones',
)]
final class CotizacionPasajerosAIdentificacionesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin guardar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var list<CotizacionFilepasajero> $pasajeros */
        $pasajeros = $this->em->getRepository(CotizacionFilepasajero::class)->findAll();

        $creadas = 0;
        $yaTenian = 0;
        $sinDato = 0;
        $filas = [];

        foreach ($pasajeros as $pasajero) {
            $tipo = $pasajero->getTipodocumento();
            $numero = trim((string) $pasajero->getNumerodocumento());

            if ($tipo === null || $numero === '') {
                ++$sinDato;
                continue;
            }

            if ($pasajero->identificacionDe($tipo) !== null) {
                ++$yaTenian;
                continue;
            }

            $identificacion = new CotizacionPasajeroIdentificacion();
            $identificacion->setTipo($tipo)->setNumero($numero)->setPaisEmisor($pasajero->getPais());
            $pasajero->addIdentificacion($identificacion);

            if (!$seco) {
                $this->em->persist($identificacion);
            }

            ++$creadas;
            $filas[] = [
                trim(sprintf('%s %s', $pasajero->getNombre(), $pasajero->getApellido())),
                $tipo->value,
                $numero,
                'sin comprobar',
            ];
        }

        if ($filas !== []) {
            $io->table(['pasajero', 'tipo', 'número', 'vencimiento'], $filas);
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d identificaciones %s · %d ya la tenían · %d sin documento en la columna vieja.',
            $creadas,
            $seco ? 'se crearían' : 'creadas',
            $yaTenian,
            $sinDato,
        ));

        if ($creadas > 0) {
            $io->warning('Ninguna trae fecha de vencimiento: la columna vieja no la guardaba. Hasta que alguien las cargue, esos documentos cuentan como SIN COMPROBAR, no como vigentes.');
        }

        return Command::SUCCESS;
    }
}
