<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Borra los adjuntos caducados: cada tipo con su propio plazo desde el retorno del grupo.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Un pasaporte que ya no hace falta y sigue guardado es riesgo puro: no aporta nada y puede
 * filtrarse. Con 133 personas por expediente —cien de ellas menores— eso no es una hipótesis
 * incómoda, es un archivo que hay que dejar de tener.
 *
 * El plazo lo puso el operador: **un mes después del retorno**. Da margen para un trámite tardío
 * —una reclamación, un seguro— y no convierte el sistema en un archivo de documentos de identidad.
 *
 * ── Qué borra y qué NO ──────────────────────────────────────────────────────
 * Borra **el fichero** y la fila del adjunto. El plazo NO está aquí: lo dice cada tipo en
 * {@see ArchivoTipoEnum::mesesDeRetencion()}, y `null` significa que no caduca. Así añadir un tipo
 * nuevo es decidir su plazo en el mismo sitio donde se declara, y no acordarse de este comando.
 *
 * ⚠️ **El boarding pass caduca igual que el pasaporte** (decisión del 08/09/2026). Lleva nombre,
 * vuelo, asiento y el localizador —con apellido y localizador se entra a la reserva en la web de
 * la aerolínea—, y son ~542 ficheros por grupo grande sin nadie que los borre. La factura, que es
 * lo que de verdad sostiene un expediente meses después, no caduca.
 *
 * ⚠️ **No toca `CotizacionPasajeroIdentificacion`**, que es el DATO —tipo, número, vencimiento,
 * país— y se queda. Esa separación es justo lo que permite borrar la foto sin perder el
 * expediente: el número de pasaporte con el que se emitió un boleto sigue ahí para siempre; la
 * imagen, no.
 *
 * ── Cuándo acaba un viaje ───────────────────────────────────────────────────
 * El retorno es la **última fecha del expediente**: el mayor de los inicios de segmento y de los
 * fines de componente, porque un viaje puede acabar en un checkout que ya no tiene servicio
 * propio. Es la misma cuenta que hace `CotizacionFileCollectionProvider` para el cuadro.
 */
#[AsCommand(
    name: 'app:cotizacion:purgar-archivos',
    description: 'Borra los adjuntos caducados: cada tipo con su plazo desde el retorno del grupo.',
)]
final class PurgarArchivosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No borra nada: enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $hoy = new DateTimeImmutable('today');

        $tipos = array_values(array_filter(
            ArchivoTipoEnum::cases(),
            static fn (ArchivoTipoEnum $t): bool => $t->mesesDeRetencion() !== null,
        ));

        /** @var list<CotizacionFilearchivo> $caducables */
        $caducables = $this->em->createQuery(
            'SELECT a FROM App\Cotizacion\Entity\CotizacionFilearchivo a WHERE a.tipoArchivo IN (:tipos)'
        )->setParameter('tipos', $tipos)->getResult();

        if ($caducables === []) {
            $io->success('No hay adjuntos con caducidad guardados.');

            return Command::SUCCESS;
        }

        /** @var array<string, ?DateTimeImmutable> $finPorFile */
        $finPorFile = [];
        $filas = [];
        $borrados = 0;

        foreach ($caducables as $archivo) {
            $file = $archivo->getFile();
            $meses = $archivo->getTipoArchivo()?->mesesDeRetencion();

            if ($file === null || $meses === null) {
                continue;
            }

            $clave = (string) $file->getId();
            $finPorFile[$clave] ??= $this->finDelViaje($file);
            $fin = $finPorFile[$clave];

            $caduca = $fin?->modify(sprintf('+%d months', $meses));
            $vencido = $caduca !== null && $caduca < $hoy;

            $filas[] = [
                (string) $file->getLocalizador(),
                $archivo->getTipoArchivo()->value ?? '—',
                trim(sprintf(
                    '%s %s',
                    (string) $archivo->getPasajero()?->getNombre(),
                    (string) $archivo->getPasajero()?->getApellido(),
                )) ?: '(sin pasajero)',
                $fin?->format('d/m/Y') ?? '— sin fechas',
                $caduca?->format('d/m/Y') ?? '—',
                $vencido ? '🔥 se borra' : 'se queda',
            ];

            if (!$vencido) {
                continue;
            }

            // El fichero primero: si falla, la fila se queda y el próximo pase lo reintenta. Al
            // revés quedaría un huérfano en disco que ya nadie sabe de quién era.
            $ruta = $this->projectDir . '/var/documentos/archivos/' . (string) $archivo->getImageName();

            if (!$seco && is_file($ruta)) {
                @unlink($ruta);
            }

            if (!$seco) {
                $this->em->remove($archivo);
            }

            ++$borrados;
        }

        $io->table(['Expediente', 'Tipo', 'Pasajero', 'Retorno', 'Caduca', ''], $filas);

        if ($seco) {
            $io->warning(sprintf('Ensayo: se borrarían %d.', $borrados));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d adjunto(s) caducado(s) borrado(s).', $borrados));

        return Command::SUCCESS;
    }

    /**
     * La última fecha del expediente: cuándo vuelve el grupo.
     *
     * Mira los inicios de segmento **y** los fines de componente, porque un viaje puede acabar en
     * un checkout sin servicio propio. Nulo si el expediente no tiene fechas todavía — y entonces
     * no se borra nada, que es lo prudente.
     */
    private function finDelViaje(CotizacionFile $file): ?DateTimeImmutable
    {
        $ultimo = null;

        foreach ($file->getCotizaciones() as $cotizacion) {
            foreach ($cotizacion->getCotservicios() as $servicio) {
                foreach ($servicio->getCotsegmentos() as $segmento) {
                    $fecha = $segmento->getFechaAbsoluta();
                    if ($fecha !== null && ($ultimo === null || $fecha > $ultimo)) {
                        $ultimo = $fecha;
                    }
                }

                foreach ($servicio->getCotcomponentes() as $componente) {
                    $fin = $componente->getFechaHoraFin();
                    if ($fin !== null && ($ultimo === null || $fin > $ultimo)) {
                        $ultimo = $fin;
                    }
                }
            }
        }

        return $ultimo === null ? null : DateTimeImmutable::createFromInterface($ultimo)->setTime(0, 0);
    }
}
