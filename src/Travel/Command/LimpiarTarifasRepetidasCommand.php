<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Cotizacion\Entity\CotizacionCottarifa;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Quita las tarifas EXACTAMENTE repetidas dentro de un mismo componente.
 *
 * «Exactamente» es literal: mismo componente, mismo nombre, mismo importe y misma moneda. No
 * decide nada —si dos difieren en algo, se quedan las dos— porque en cuanto hay una diferencia
 * deja de ser un duplicado y pasa a ser una decisión de negocio. Ver
 * `app:travel:resolver-tarifas-divergentes` para eso.
 *
 * ⚠️ **Siempre queda UNA.** Un componente sin tarifas no se puede cotizar: entra sin precio y no
 * falla en ninguna parte hasta que alguien mira el total. Vaciarlo sería cambiar «tiene siete
 * copias» por «no se puede vender», que es peor.
 *
 * El caso que lo motivó: `Pool By Car` tenía **siete** «Pool Bickmar» idénticas a 40 USD, ninguna
 * en uso. Siete filas iguales en un desplegable no es un catálogo, es ruido — y esconde si alguna
 * de ellas era la buena.
 *
 * La superviviente hereda lo que apuntaba a las demás: relaciones del catálogo y citas de
 * cotización. Aquí no había ninguna, pero el olvido equivalente en la fusión bidireccional dejó
 * trece enlaces al vacío, así que se hace siempre.
 */
#[AsCommand(
    name: 'app:travel:limpiar-tarifas-repetidas',
    description: 'Deja una sola tarifa cuando hay copias exactas dentro del mismo componente.',
)]
final class LimpiarTarifasRepetidasCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('componente', null, InputOption::VALUE_REQUIRED, 'Limita a un componente por su nombre interno.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña qué quitaría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $filtro = $input->getOption('componente');
        $filtro = is_string($filtro) ? $filtro : null;

        $repo = $this->em->getRepository(TravelComponente::class);
        $componentes = $filtro !== null
            ? array_filter([$repo->findOneBy(['nombreInterno' => $filtro])])
            : $repo->findAll();

        if ($componentes === []) {
            $io->error(sprintf('No existe el componente «%s».', (string) $filtro));

            return Command::FAILURE;
        }

        $quitadas = 0;

        foreach ($componentes as $componente) {
            /** @var array<string, list<TravelTarifa>> $porClave */
            $porClave = [];

            foreach ($componente->getTarifas() as $tarifa) {
                $clave = sprintf(
                    '%s|%s|%s',
                    mb_strtolower(trim($tarifa->getNombreInterno() ?? '')),
                    $tarifa->getMonto() ?? '',
                    $tarifa->getMoneda()?->getId() ?? '',
                );
                $porClave[$clave][] = $tarifa;
            }

            $cabecera = false;

            foreach ($porClave as $copias) {
                if (count($copias) < 2) {
                    continue;
                }

                if (!$cabecera) {
                    $io->section((string) $componente->getNombreInterno());
                    $cabecera = true;
                }

                $sobrevive = $copias[0];
                $sobran = array_slice($copias, 1);

                $io->text(sprintf(
                    '  %s · «%s» %s %s — %d copias, se queda 1',
                    $simula ? 'quitaría' : 'quitadas',
                    $sobrevive->getNombreInterno() ?? '',
                    $sobrevive->getMonto() ?? '',
                    $sobrevive->getMoneda()?->getId() ?? '',
                    count($copias),
                ));

                $quitadas += count($sobran);

                if ($simula) {
                    continue;
                }

                foreach ($sobran as $sobra) {
                    $this->ceder($sobra, $sobrevive);
                    $this->em->remove($sobra);
                }
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf('  tarifas quitadas: %d', $quitadas));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function ceder(TravelTarifa $muere, TravelTarifa $sobrevive): void
    {
        foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['tarifaPredeterminada' => $muere]) as $rel) {
            $rel->setTarifaPredeterminada($sobrevive);
        }

        foreach ($this->em->getRepository(CotizacionCottarifa::class)->findBy(['tarifaMaestraId' => (string) $muere->getId()]) as $cita) {
            $cita->setTarifaMaestraId((string) $sobrevive->getId());
        }
    }
}
