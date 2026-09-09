<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Documento\LectorDeDocumentoIdentidad;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Vich\UploaderBundle\Storage\StorageInterface;

/**
 * Lee un documento de la bóveda y enseña qué sacaría, SIN guardar nada.
 *
 * 🔑 **Existe para medir antes de construir.** Antes de dejar que esto escriba en el manifiesto
 * hay que saber cuánto acierta con documentos de verdad —los de este grupo, fotografiados por
 * ellos con su móvil—, y eso no lo dice ningún test unitario. Es la misma disciplina del ZIP:
 * plan primero, aplicar después.
 *
 * Con `--todos` recorre los documentos de identidad de un expediente y saca el resumen, que es lo
 * que dice si la tasa de acierto da para automatizar algo o no.
 */
#[AsCommand(
    name: 'app:cotizacion:leer-documento',
    description: 'Lee un documento de identidad de la bóveda con IA y enseña lo que sacaría (no guarda nada).',
)]
final class CotizacionLeerDocumentoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LectorDeDocumentoIdentidad $lector,
        private readonly StorageInterface $almacen,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'UUID de un CotizacionFilearchivo, o de un CotizacionFile con --todos')
            ->addOption('todos', null, null, 'Trata el id como expediente y lee todos sus documentos de identidad');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (string) $input->getArgument('id');

        if (!Uuid::isValid($id)) {
            $io->error('Eso no es un UUID.');

            return Command::INVALID;
        }

        $archivos = $input->getOption('todos')
            ? $this->deExpediente($id)
            : array_filter([$this->em->getRepository(CotizacionFilearchivo::class)->find($id)]);

        if ($archivos === []) {
            $io->error('No hay nada que leer con ese id.');

            return Command::FAILURE;
        }

        $leidos = 0;
        $verificados = 0;
        $conAvisos = 0;

        foreach ($archivos as $archivo) {
            $io->section((string) $archivo);

            $ruta = $this->almacen->resolvePath($archivo, 'imageFile');
            if (!is_string($ruta) || !is_readable($ruta)) {
                $io->warning('El fichero no está en disco.');
                continue;
            }

            try {
                $datos = $this->lector->leer((string) file_get_contents($ruta), (string) mime_content_type($ruta));
            } catch (Throwable $e) {
                // No se corta: en una tirada de cien, uno ilegible no puede parar los otros 99.
                $io->warning('No se pudo leer: ' . $e->getMessage());
                continue;
            }

            ++$leidos;
            $datos->verificadoPorMrz() && ++$verificados;
            $datos->avisos !== [] && ++$conAvisos;

            $io->definitionList(
                ['tipo' => $datos->tipo !== null ? $datos->tipo->value : '—'],
                ['número' => $datos->numero ?? '—'],
                ['nombre' => trim(($datos->nombres ?? '') . ' ' . ($datos->apellidos ?? '')) ?: '—'],
                ['nacimiento' => $datos->nacimiento?->format('Y-m-d') ?? '—'],
                ['vencimiento' => $datos->vencimiento?->format('Y-m-d') ?? '—'],
                ['país' => $datos->paisEmisor ?? '—'],
                // La línea que importa: si esto dice «no», lo de arriba es sólo lo que dijo un
                // modelo, y se enseña distinto en pantalla.
                ['comprobado con MRZ' => $datos->verificadoPorMrz() ? 'sí' : 'NO'],
            );

            foreach ($datos->avisos as $aviso) {
                $io->text('  ⚠️  ' . $aviso);
            }
        }

        $io->success(sprintf(
            '%d leídos · %d comprobados con MRZ · %d con avisos. NO se ha guardado nada.',
            $leidos,
            $verificados,
            $conAvisos,
        ));

        return Command::SUCCESS;
    }

    /** @return list<CotizacionFilearchivo> */
    private function deExpediente(string $fileId): array
    {
        /** @var list<CotizacionFilearchivo> $todos */
        $todos = $this->em->getRepository(CotizacionFilearchivo::class)
            ->createQueryBuilder('a')
            // ⚠️ Ver el aviso de `CotizacionValidarDocumentosCommand`: `binary(16)` contra
            // texto no casa nunca y devuelve cero filas sin quejarse. Aquí llevaba desde que se
            // escribió y no se había notado porque `--todos` no se había usado.
            ->andWhere('a.file = :f')->setParameter('f', Uuid::fromString($fileId), UuidType::NAME)
            ->getQuery()->getResult();

        // ⚠️ `esEscaneoDeIdentidad()` ya existe en el enum y decide esto en un solo sitio. Una
        // lista de tipos escrita aquí se quedaría corta el día que se añada uno —y no daría
        // error: simplemente dejaría de leer esos documentos, en silencio.
        return array_values(array_filter(
            $todos,
            static fn (CotizacionFilearchivo $a): bool => $a->getTipoArchivo()?->esEscaneoDeIdentidad() === true,
        ));
    }
}
