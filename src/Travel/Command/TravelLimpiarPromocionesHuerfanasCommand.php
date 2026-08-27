<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Quita la marca de «servicio principal del día» a las filas que no tienen plantilla.
 *
 * ── Qué son esas filas ──────────────────────────────────────────────────────
 * `horaServicioCompleto = true` con `itinerarioContexto = null`. Sin plantilla no hay día que
 * abarcar, así que la marca **no significa nada**: ni el resolvedor del catálogo la mira, ni
 * podría —no existe una lista ordenada de segmentos a ese nivel—.
 *
 * Son anteriores a las dos defensas que hoy lo impiden:
 * {@see TravelSegmentoComponente::validarPromocionRequierePlantilla()} en la validación, y
 * {@see \App\Travel\EventListener\TravelSegmentoComponentePromocionUnicaListener} en el flush.
 * Por eso esto es una limpieza de una vez, no un mantenimiento periódico: por el ORM ya no
 * entran más. Sólo un `UPDATE` en SQL podría volver a crearlas.
 *
 * ── Por qué molestan si «no hacen nada» ─────────────────────────────────────
 * Porque en la COTIZACIÓN sí hacen algo. `CotizacionPuntosDelServicio` no exige plantilla —allí
 * el contenedor ordenado es el `CotizacionCotservicio`, y sí existe—, así que una marca que el
 * catálogo considera inválida acaba decidiendo la cabecera de un servicio cotizado. Hoy pasa con
 * dos componentes. Limpiando el origen, no se hereda ninguna más.
 *
 * ⚠️ **NO toca `itinerarioContexto`, y es deliberado.** `null` significa «este componente se
 * inyecta siempre que se use el segmento» —la logística global del pool—. Ponerle una plantilla
 * restringiría la inyección, y eso no es limpieza: es cambiar cuándo entra el componente, sin
 * aviso, el día que alguien monte un servicio a medida con ese segmento.
 *
 * ⚠️ **NO toca las cotizaciones ya guardadas.** El flag copiado en un `CotizacionCotcomponente`
 * es parte de un documento emitido, y en algún caso —Pool Vinicunca— además acierta: ese pool
 * **es** su día. Retirarlo cambiaría documentos existentes para dejar limpia una tabla.
 */
#[AsCommand(
    name: 'app:travel:limpiar-promociones-huerfanas',
    description: 'Quita la marca de servicio principal a las filas sin plantilla de contexto.',
)]
final class TravelLimpiarPromocionesHuerfanasCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe nada: sólo enseña lo que haría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var list<TravelSegmentoComponente> $huerfanas */
        $huerfanas = $this->em->getRepository(TravelSegmentoComponente::class)
            ->findBy(['horaServicioCompleto' => true, 'itinerarioContexto' => null]);

        if ($huerfanas === []) {
            $io->success('No hay promociones huérfanas. Nada que hacer.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('%d promociones sin plantilla', count($huerfanas)));

        foreach ($huerfanas as $sc) {
            $io->writeln(sprintf(
                '  <info>·</info> %-30s [%s] cuelga de «%s»',
                mb_substr((string) $sc->getComponente()?->getNombreInterno(), 0, 29),
                $sc->getComponente()?->getTipo()->value ?? '?',
                mb_substr((string) $sc->getSegmento()?->getNombreInterno(), 0, 45)
            ));

            // Se pone a mano en vez de confiar en que el listener lo haga al detectar el update:
            // el listener sólo actúa sobre entidades que YA están programadas para escribirse, y
            // sin este cambio no habría nada que programar. Depender de él sería no hacer nada.
            $sc->setHoraServicioCompleto(false);
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%d marcas %s. La inyección NO cambia: `itinerarioContexto` sigue en null.',
            count($huerfanas),
            $seco ? 'se quitarían' : 'quitadas'
        ));

        return Command::SUCCESS;
    }
}
