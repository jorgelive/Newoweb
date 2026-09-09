<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Documento\Accion;
use App\Cotizacion\Documento\ValidadorDeDocumento;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Enum\ValidacionDocumentoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Pasa el control de validación por los documentos de identidad de un expediente.
 *
 * ⚠️ **Sin `--aplicar` no escribe NADA**, ni siquiera el estado: enseña el plan y se va. Es la
 * misma forma que la carga por ZIP, y aquí importa más, porque una de las acciones **crea
 * personas** en el manifiesto.
 *
 * Con `--aplicar` escribe el estado y las observaciones de todos, y ejecuta las asociaciones
 * **seguras** —las que casan por número de documento—. Crear fichas y asociar por parecido de
 * nombre **nunca** se hacen aquí: son las dos decisiones que un humano tiene que tomar mirando,
 * y son exactamente donde una familia con apellidos repetidos sale mal.
 */
#[AsCommand(
    name: 'app:cotizacion:validar-documentos',
    description: 'Coteja los documentos escaneados de un expediente con el manifiesto y propone qué hacer.',
)]
final class CotizacionValidarDocumentosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidadorDeDocumento $validador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('expediente', InputArgument::REQUIRED, 'UUID del CotizacionFile')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Guarda el estado y ejecuta las asociaciones seguras')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Cuántos documentos como mucho', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $expediente = (string) $input->getArgument('expediente');
        $aplicar = (bool) $input->getOption('aplicar');
        $limite = (int) $input->getOption('limite');

        if (!Uuid::isValid($expediente)) {
            $io->error('Eso no es un UUID.');

            return Command::INVALID;
        }

        /** @var list<CotizacionFilearchivo> $todos */
        $todos = $this->em->getRepository(CotizacionFilearchivo::class)
            ->createQueryBuilder('a')
            // ⚠️ **El id va como Uuid tipado, no como cadena.** La columna es `binary(16)`, así
            // que un `setParameter()` con el texto del UUID compara 36 caracteres contra 16
            // bytes: no casa **nunca**, y no da error — devuelve cero filas y el comando dice
            // «este expediente no tiene documentos», que suena a dato y es un fallo.
            ->andWhere('a.file = :f')->setParameter('f', Uuid::fromString($expediente), UuidType::NAME)
            ->getQuery()->getResult();

        $archivos = array_values(array_filter(
            $todos,
            // ⚠️ `esValidable()`, NO `esEscaneoDeIdentidad()`: son dos conjuntos distintos. Sólo
            // el pasaporte y el anverso del DNI traen número y nombre que cotejar; el reverso y
            // la autorización no se pueden validar, y metidos aquí salían como «no se pudo leer
            // el número» — que suena a mala calidad y manda a pedir otra vez algo que nunca tuvo
            // el dato. Con 86 reversos, serían 86 filas de ruido en la cola.
            static fn (CotizacionFilearchivo $a): bool => $a->getTipoArchivo()?->esValidable() === true,
        ));

        if ($limite > 0) {
            $archivos = array_slice($archivos, 0, $limite);
        }

        if ($archivos === []) {
            $io->warning('Ese expediente no tiene documentos de identidad.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d documentos · %s', count($archivos), $aplicar ? 'APLICANDO' : 'sólo plan, no se escribe nada'));

        $filas = [];
        $conteo = [ValidacionDocumentoEnum::VALIDADO->value => 0, ValidacionDocumentoEnum::OBSERVADO->value => 0, ValidacionDocumentoEnum::NO_VALIDADO->value => 0];
        $asociados = 0;
        $porRevisar = 0;

        foreach ($archivos as $archivo) {
            $resultado = $this->validador->analizar($archivo);
            ++$conteo[$resultado->estado->value];

            $accion = match ($resultado->accion) {
                Accion::NINGUNA => '—',
                Accion::ASOCIAR => 'asociar → ' . trim(($resultado->candidato?->getNombre() ?? '') . ' ' . ($resultado->candidato?->getApellido() ?? '')),
                Accion::CREAR => 'CREAR ficha',
            };

            if ($aplicar) {
                $archivo->registrarValidacion($resultado->estado, $resultado->observaciones);

                // Sólo lo seguro: casar por número es un hecho, casar por nombre es una opinión.
                if ($resultado->accion === Accion::ASOCIAR && $resultado->candidato !== null
                    && str_contains($resultado->motivo, 'mismo número')) {
                    $archivo->setPasajero($resultado->candidato);
                    ++$asociados;
                    $accion .= ' ✔';
                } elseif ($resultado->accion !== Accion::NINGUNA) {
                    ++$porRevisar;
                    $accion .= ' (a mano)';
                }
            }

            $filas[] = [
                mb_substr((string) $archivo, 0, 28),
                $resultado->estado->getLabel(),
                $resultado->leido?->verificadoPorMrz() ? 'MRZ' : '—',
                $accion,
                mb_substr(implode(' · ', $resultado->observaciones), 0, 60),
            ];
        }

        if ($aplicar) {
            $this->em->flush();
        }

        $io->table(['documento', 'estado', 'respaldo', 'acción', 'observaciones'], $filas);

        $io->success(sprintf(
            '%d validados · %d observados · %d sin validar%s',
            $conteo[ValidacionDocumentoEnum::VALIDADO->value],
            $conteo[ValidacionDocumentoEnum::OBSERVADO->value],
            $conteo[ValidacionDocumentoEnum::NO_VALIDADO->value],
            $aplicar
                ? sprintf('. Guardado: %d asociados por número, %d esperando decisión humana.', $asociados, $porRevisar)
                : '. NO se ha escrito nada: repite con --aplicar.',
        ));

        // Se nombra la clase para que quien lea esto sepa dónde vive lo que NO hace el comando.
        if (!$aplicar) {
            $io->note(sprintf(
                'Crear fichas y asociar por nombre no los hace nunca este comando: son las dos decisiones que se toman mirando. Ver %s.',
                CotizacionPasajeroIdentificacion::class,
            ));
        }

        return Command::SUCCESS;
    }
}
