<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los traslados de llegada decían «a Cusco» y recogían en el aeropuerto.
 *
 * ── Dos fallos, y el segundo es el caro ─────────────────────────────────────
 *
 * **El nombre no identificaba la fila.** «Recepción y traslado a Cusco» y «Recepción y traslado
 * al hotel en Cusco» sirven igual al que llega de San Pedro, de Ollantaytambo o de cualquier
 * otro sitio. En la Orden de Servicio el nombre del SEGMENTO es lo que va en grande para un
 * `TRANSPORTE` ({@see \App\Travel\Enum\ComponenteTipoEnum::mandaElSegmento()}), así que dos
 * encargos distintos llegaban con el mismo titular.
 *
 * ⚠️ **Y el punto de inicio era el Aeropuerto de Cusco en los dos.** El texto de cada uno decía
 * bien de dónde venía —«a su llegada a la estación de tren de San Pedro», «a su llegada a la
 * estación de Ollantaytambo»— pero el punto decía otra cosa, y el punto es lo que contesta
 * «dónde recojo» en la orden. Un proveedor que lea el campo y no la prosa va al aeropuerto a
 * esperar un tren.
 *
 * Parece un copiar y pegar de un traslado de aeropuerto, que es de donde salen casi todos los
 * «Recepción y traslado». El caso lo destapó leer una ficha en el constructor de storytelling:
 * título genérico arriba y la estación correcta en el cuerpo.
 *
 * ⚠️ **El contenido NO se toca**: ya decía la verdad. Lo que se corrige es el nombre, el título
 * público y el punto, que es donde estaba la mentira.
 */
#[AsCommand(
    name: 'app:travel:corregir-recepciones-mapi',
    description: 'Da nombre propio a los traslados de llegada y corrige su punto de recojo.',
    hidden: true,
)]
final class CorregirRecepcionesMapiCommand extends Command
{
    /**
     * `punto` es el extremo de INICIO que debería tener; null deja el que haya.
     *
     * @var array<string, array{nombre: string, titulo: string, punto: string|null}>
     */
    private const CORRECCIONES = [
        'TRANS_DIRECT_RET-MAPI-SPEDRO_CUZ' => [
            'nombre' => 'Recepción en la estación San Pedro y traslado al hotel',
            'titulo' => 'Llegada a San Pedro y traslado al hotel',
            'punto' => 'Estación San Pedro',
        ],
        'TRANS_DIRECT_RET-MAPI-OLL_CUZ' => [
            'nombre' => 'Recepción en Ollantaytambo y traslado al hotel en Cusco',
            'titulo' => 'Llegada a Ollantaytambo y traslado al hotel',
            'punto' => 'Estación de Ollantaytambo',
        ],
        // Éste nació hoy con el punto correcto; sólo su título público era igual de vago.
        'TRANS_DIRECT_RET-MAPI-OLL_CENTRO' => [
            'nombre' => 'Traslado de Ollantaytambo al centro de Cusco (servicio compartido)',
            'titulo' => 'De Ollantaytambo al centro de Cusco',
            'punto' => null,
        ],
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

        $io->title('Traslados de llegada');
        $tocados = 0;

        foreach (self::CORRECCIONES as $slug => $def) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $slug));
                continue;
            }

            $cambios = [];

            if ($segmento->getNombreInterno() !== $def['nombre']) {
                $cambios[] = sprintf('nombre «%s» → «%s»', $segmento->getNombreInterno(), $def['nombre']);
            }

            // Igual que en los bimodales: no basta con que el español coincida, hay que
            // comprobar que se tradujo. Un listener que falló dejaría el texto en un idioma y
            // la comparación lo daría por bueno para siempre.
            $traducido = count($segmento->getTitulo()) > 1;

            if (!$traducido || ($segmento->getTitulo()[0]['content'] ?? '') !== $def['titulo']) {
                $cambios[] = sprintf('título «%s» → «%s»', $segmento->getTitulo()[0]['content'] ?? '', $def['titulo']);
            }

            $punto = null;

            if ($def['punto'] !== null) {
                $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $def['punto']]);

                if ($punto === null) {
                    $io->error(sprintf('  no existe el punto «%s».', $def['punto']));

                    return Command::FAILURE;
                }

                if ($segmento->getInicioPunto() !== $punto) {
                    $cambios[] = sprintf(
                        '🔥 recojo «%s» → «%s»',
                        $segmento->getInicioPunto()?->getNombre() ?? '(ninguno)',
                        $def['punto'],
                    );
                }
            }

            if ($cambios === []) {
                $io->text(sprintf('  ya está  · %s', $slug));
                continue;
            }

            ++$tocados;
            $io->text(sprintf('  %s · %s', $simula ? 'haría ' : 'hecho ', $slug));

            foreach ($cambios as $cambio) {
                $io->text(sprintf('             %s', $cambio));
            }

            if ($simula) {
                continue;
            }

            $segmento->setSobreescribirTraduccion(true);
            $segmento->setNombreInterno($def['nombre']);
            $segmento->setTitulo([['language' => 'es', 'content' => $def['titulo']]]);

            if ($punto !== null) {
                $segmento->setInicioPunto($punto);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf('%s %d segmento(s).', $simula ? 'Se corregirían' : 'Corregidos', $tocados));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }
}
