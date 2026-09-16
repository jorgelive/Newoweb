<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Documento\ValidadorDeEticket;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\Enum\PaisDeControlEnum;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Coteja los E-Ticket migratorios de un expediente contra los vuelos de cada persona.
 *
 * ── Qué contesta ────────────────────────────────────────────────────────────
 * «¿A quién hay que escribirle, y qué decirle?» — que es una pregunta distinta de «¿quién no lo ha
 * mandado?», y hasta ahora sólo se sabía contestar la segunda. Un trámite mandado con el vuelo
 * equivocado o sin la sección de salida cuenta como mandado en la hoja de control y **no sirve en
 * el mostrador**.
 *
 * ⚠️ **La lectura se paga una vez por documento.** La primera pasada llama al modelo y guarda lo
 * leído en `CotizacionFilearchivo::$datosLeidos`; las siguientes son gratis y aplican el criterio
 * de hoy. Afinar una regla no cuesta una llamada más.
 *
 * ⚠️ **No escribe ningún veredicto todavía**: informa. Cuando el veredicto tenga dónde vivir en
 * `CotizacionFilearchivo`, este comando es el sitio donde se persiste.
 *
 *   php bin/console app:cotizacion:validar-etickets 5SRAJV --limite=5
 */
#[AsCommand(
    name: 'app:cotizacion:validar-etickets',
    description: 'Coteja los E-Ticket migratorios contra los vuelos y el pasaporte de cada pasajero.',
)]
final class CotizacionValidarEticketsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidadorDeEticket $validador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('localizador', InputArgument::REQUIRED, 'El localizador del expediente')
            ->addOption('pais', null, InputOption::VALUE_REQUIRED, 'País del trámite (ISO-2)', 'DO')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Cuántos leer como mucho en esta pasada')
            ->addOption('solo-leidos', null, InputOption::VALUE_NONE, 'No llama al modelo: sólo re-juzga lo ya leído')
            ->addOption('reintentar', null, InputOption::VALUE_NONE, 'Vuelve a leer los que fallaron al leerse');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pais = PaisDeControlEnum::tryFrom((string) $input->getOption('pais'));

        if ($pais === null) {
            $io->error(sprintf(
                'No hay trámite declarado para «%s». Los que hay: %s. Se añaden en PaisDeControlEnum.',
                (string) $input->getOption('pais'),
                implode(', ', array_map(static fn (PaisDeControlEnum $p): string => $p->value, PaisDeControlEnum::cases())),
            ));

            return Command::FAILURE;
        }

        $file = $this->em->getRepository(CotizacionFile::class)
            ->findOneBy(['localizador' => (string) $input->getArgument('localizador')]);

        if ($file === null) {
            $io->error('No existe ese expediente.');

            return Command::FAILURE;
        }

        $limite = $input->getOption('limite');
        $limite = is_string($limite) ? max(1, (int) $limite) : null;
        $soloLeidos = (bool) $input->getOption('solo-leidos');
        $reintentar = (bool) $input->getOption('reintentar');

        $filas = [];
        $cuenta = [];
        $leidos = 0;

        foreach ($file->getFilearchivos() as $archivo) {
            if ($archivo->getTipoArchivo() !== ArchivoTipoEnum::ETICKET) {
                continue;
            }

            // ⚠️ Un fallo de lectura se guarda —si no, la tanda lo reintentaría eternamente— y eso
            // deja el documento en «se intentó y falló» PARA SIEMPRE. Cuando lo que falló fue el
            // llamador y no el documento —un esquema que el proveedor rechaza, una credencial
            // caducada—, hay que poder devolverlos a «nunca se ha leído». Para eso existe
            // `olvidarLectura()`, que no es `registrarLectura(null)`.
            if ($reintentar && $archivo->getDatosLeidos() === null && $archivo->seIntentoLeer()) {
                $archivo->olvidarLectura();
            }

            // Con `--solo-leidos` no se llama al modelo: es la pasada barata, la que se usa después
            // de tocar una regla para ver qué cambia sin gastar nada.
            if ($soloLeidos && $archivo->getDatosLeidos() === null) {
                continue;
            }

            if ($limite !== null && $archivo->getDatosLeidos() === null && $leidos >= $limite) {
                continue;
            }

            if ($archivo->getDatosLeidos() === null) {
                ++$leidos;
            }

            $pasajero = $archivo->getPasajero();
            $cotejo = $this->validador->validar($archivo, $pais);

            if ($cotejo === null) {
                $cuenta['sin leer o sin dueño'] = ($cuenta['sin leer o sin dueño'] ?? 0) + 1;
                continue;
            }

            $cuenta[$cotejo->estado->value] = ($cuenta[$cotejo->estado->value] ?? 0) + 1;

            // Lo que está bien no se lista: la salida de este comando es una lista de trabajo.
            if ($cotejo->estado === ValidacionIdentificacionEnum::VALIDADO_OCR) {
                continue;
            }

            $filas[] = [
                trim(($pasajero?->getNombre() ?? '').' '.($pasajero?->getApellido() ?? '')),
                $cotejo->estado->value,
                mb_substr($cotejo->resumen(), 0, 90),
            ];
        }

        // El flush guarda las LECTURAS —que es lo que costó dinero—, no veredictos.
        $this->em->flush();

        if ($filas !== []) {
            $io->table(['pasajero', 'estado', 'qué pasa'], $filas);
        }

        foreach ($cuenta as $estado => $n) {
            $io->writeln(sprintf('  %-22s %d', $estado, $n));
        }

        $io->success(sprintf('%d con algo que mirar. Lecturas nuevas pagadas: %d.', count($filas), $leidos));

        return Command::SUCCESS;
    }
}
