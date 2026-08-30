<?php

declare(strict_types=1);

namespace App\Cotizacion\Command;

use App\Cotizacion\Entity\CotizacionSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Vuelve a bajar título y contenido desde el segmento maestro a un expediente ya armado.
 *
 * `CotizacionSegmento` **congela** `tituloSnapshot` y `contenidoSnapshot` al insertar, y eso está
 * bien: una propuesta enviada tiene que seguir diciendo lo que decía el día que se mandó, aunque
 * después se reescriba el catálogo. El precio de esa garantía es que **mejorar el maestro no
 * llega a lo abierto**, y hasta ahora no había forma de bajarlo salvo quitar el segmento y
 * volverlo a meter.
 *
 * `app:cotizacion:refrescar-nombres-maestros` ya existía, pero refresca el **nombre interno** —lo
 * que ve el operador—, no el texto del cliente.
 *
 * ⚠️ **El peligro está en que un retoque a mano y un texto viejo son indistinguibles.** Si el
 * operador editó la descripción dentro del expediente, aquí se ve exactamente igual que un
 * snapshot desactualizado: los dos «difieren del maestro». Por eso el comando **enseña las dos
 * versiones y no adivina**: se corre primero con `--dry-run`, se lee, y se acota con `--solo` si
 * hay alguno que no se quiere pisar. No hay heurística que distinga las dos cosas, así que no se
 * finge que la haya.
 */
#[AsCommand(
    name: 'app:cotizacion:refrescar-textos-maestros',
    description: 'Baja título y contenido del segmento maestro a un expediente ya armado.',
)]
final class RefrescarTextosMaestrosCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cotizacion', null, InputOption::VALUE_REQUIRED, 'UUID de la cotización.')
            ->addOption('solo', null, InputOption::VALUE_REQUIRED, 'Slugs separados por coma; el resto se salta.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $cotizacionId = (string) $input->getOption('cotizacion');

        if ($cotizacionId === '' || !Uuid::isValid($cotizacionId)) {
            $io->error('Hace falta --cotizacion con un UUID válido.');

            return Command::FAILURE;
        }

        $solo = array_filter(array_map('trim', explode(',', (string) $input->getOption('solo'))));

        $segmentos = $this->em->createQueryBuilder()
            ->select('seg')
            ->from(CotizacionSegmento::class, 'seg')
            ->join('seg.cotservicio', 'cs')
            ->where('cs.cotizacion = :cot')
            ->setParameter('cot', Uuid::fromString($cotizacionId))
            ->getQuery()
            ->getResult();

        $tocados = 0;
        $saltados = 0;

        foreach ($segmentos as $segmento) {
            $maestro = $segmento->getSegmentoMaestro();

            if ($maestro === null) {
                $io->text('  sin maestro · no se puede refrescar');
                continue;
            }

            $slug = (string) $maestro->getSlug();

            if ($solo !== [] && !in_array($slug, $solo, true)) {
                ++$saltados;
                continue;
            }

            $tituloViejo = $this->español($segmento->getTituloSnapshot());
            $tituloNuevo = $this->español($maestro->getTitulo());
            $textoViejo = $this->español($segmento->getContenidoSnapshot());
            $textoNuevo = $this->español($maestro->getContenido());

            if ($tituloViejo === $tituloNuevo && $textoViejo === $textoNuevo) {
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'refrescaría' : 'refrescado ', $slug));

            if ($tituloViejo !== $tituloNuevo) {
                $io->text(sprintf('      título  %s', $tituloViejo === '' ? '(vacío)' : $tituloViejo));
                $io->text(sprintf('            → %s', $tituloNuevo));
            }

            if ($textoViejo !== $textoNuevo) {
                $io->text(sprintf('      texto   %s', $textoViejo === '' ? '(vacío)' : $this->recorte($textoViejo)));
                $io->text(sprintf('            → %s', $this->recorte($textoNuevo)));
            }

            ++$tocados;

            if (!$simula) {
                // Se copian los SIETE idiomas del maestro, no sólo el español: el snapshot es lo
                // que lee `pax` en el idioma del huésped, y dejarlo con un español nuevo y seis
                // traducciones viejas sería peor que no tocarlo.
                $segmento->setTituloSnapshot($maestro->getTitulo());
                $segmento->setContenidoSnapshot($maestro->getContenido());
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->text('');
        $io->text(sprintf('  segmentos refrescados: %d%s', $tocados, $saltados > 0 ? sprintf(' · saltados por --solo: %d', $saltados) : ''));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $i18n
     */
    private function español(array $i18n): string
    {
        foreach ($i18n as $bloque) {
            if (($bloque['language'] ?? '') === 'es') {
                return (string) ($bloque['content'] ?? '');
            }
        }

        return '';
    }

    private function recorte(string $texto): string
    {
        return mb_strlen($texto) > 80 ? mb_substr($texto, 0, 80).'…' : $texto;
    }
}
