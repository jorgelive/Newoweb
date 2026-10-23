<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * En Puno no hay terminal de Cruz del Sur: sale del Terminal Terrestre, como Transzela (23/10/2026).
 *
 * `app:travel:terminales-cruz-del-sur` creó un punto «Terminal Cruz del Sur Puno» en el jirón 1 de
 * Mayo 703 —la dirección que la web de Cruz del Sur da para Puno— y dos segmentos que llevaban a él.
 * Jorge: «Cruz del Sur sale del terminal terrestre en Puno igual que Transzela (jr 1 de Mayo)». Esa
 * dirección ES la del Terminal Terrestre. En CUSCO sí tiene terminal propio (Av. Industrial), y eso
 * no se toca.
 *
 * - El Terminal Terrestre de Puno recibe la dirección, que no tenía.
 * - Se borran el punto duplicado y sus dos segmentos (`REC-PUNO-TERM_CDS_PUN`,
 *   `TRANS-CENTRO_PUN-TERM_CDS_PUN`). Borrar y no marcar porque son catálogo creado hoy por error y
 *   nada los usa: ni una cotización ni una plantilla —el comando lo comprueba antes y, si alguna
 *   los usa, para—. Con las dos empresas en el mismo terminal, el de Puno vale para las dos.
 */
#[AsCommand(
    name: 'app:travel:retirar-terminal-cruz-del-sur-puno',
    description: 'Quita el terminal de Cruz del Sur en Puno, que es el Terminal Terrestre.',
    hidden: true,
)]
final class RetirarTerminalCruzDelSurPunoCommand extends Command
{
    private const string PUNTO = 'Terminal Cruz del Sur Puno';
    private const string TERMINAL = 'Terminal Terrestre de Puno';
    private const string DIRECCION = 'Jr. 1 de Mayo 703-705, Barrio César Vallejo, Puno';
    private const array SEGMENTOS = ['REC-PUNO-TERM_CDS_PUN', 'TRANS-CENTRO_PUN-TERM_CDS_PUN'];

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
        $seco = (bool) $input->getOption('dry-run');

        $terminal = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => self::TERMINAL]);
        if (!$terminal instanceof TravelPunto) {
            $io->error('No existe el Terminal Terrestre de Puno.');

            return Command::FAILURE;
        }
        if ($terminal->getDireccion() !== self::DIRECCION) {
            $io->text(sprintf('· %s — dirección: %s', self::TERMINAL, self::DIRECCION));
            if (!$seco) {
                $terminal->setDireccion(self::DIRECCION);
            }
        }

        foreach (self::SEGMENTOS as $slug) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);
            if (!$segmento instanceof TravelSegmento) {
                $io->text(sprintf('· %s — ya no existe', $slug));
                continue;
            }

            $usos = $this->em->getConnection()->fetchOne(
                'SELECT (SELECT COUNT(*) FROM cotizacion_segmento WHERE segmento_maestro_id = ?)'
                . ' + (SELECT COUNT(*) FROM travel_itinerario_segmento_rel WHERE segmento_id = UUID_TO_BIN(?))',
                [(string) $segmento->getId(), (string) $segmento->getId()],
            );
            if (is_numeric($usos) && (int) $usos > 0) {
                $io->error(sprintf('%s lo usan %d cotizaciones o plantillas: no se borra.', $slug, (int) $usos));

                return Command::FAILURE;
            }

            $io->text(sprintf('· %s — %s', $slug, $seco ? 'se borraría' : 'borrado'));
            if ($seco) {
                continue;
            }

            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['segmento' => $segmento]) as $pivote) {
                $this->em->remove($pivote);
            }
            foreach ($segmento->getServicios()->toArray() as $servicio) {
                $servicio->removeSegmento($segmento);
            }
            $this->em->remove($segmento);
        }

        $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => self::PUNTO]);
        if ($punto instanceof TravelPunto) {
            $io->text(sprintf('· Punto «%s» — %s', self::PUNTO, $seco ? 'se borraría' : 'borrado'));
            if (!$seco) {
                $this->em->remove($punto);
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
