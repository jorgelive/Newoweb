<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las entradas de la Ruta del Sol: el boleto de Pukará repetido, y Mer como comprador (23/10/2026).
 *
 * ── El duplicado ────────────────────────────────────────────────────────────
 * `app:travel:crear-ruta-del-sol` buscó «Ingreso al Museo Lítico de Pukará» por nombre exacto y, al
 * no encontrarlo, lo creó. Existía desde abril como «Ingreso a Museo Litico de Pucará» —sin tilde y
 * con c—, con la misma tarifa (General, S/ 15) y título público. Lo destapó Jorge preguntando si el
 * de Andahuaylillas ya estaba: ése sí se reutilizó. Se pasan las dos plantillas al viejo y se borra
 * el nuevo: creado hoy por error, sin una cotización que lo use (el comando lo comprueba).
 *
 * ⚠️ La lección, que ya estaba escrita para las tildes (`docs/TravelCargaDeCatalogo.md`, «findOneBy
 * por nombre NO distingue tildes»): antes de crear un componente, búscalo por la PALABRA que lo
 * identifica —«Puka», «Pucar»—, no por el nombre que uno escribiría hoy.
 *
 * ── El comprador ────────────────────────────────────────────────────────────
 * Jorge: «Lo hace Mer, yo le pago con todo, ellos son los compradores». Turismo Mer compra los
 * boletos en ruta y los cobra junto con el bus. Se pone como comprador en las tarifas de pago de
 * las tres entradas; el prestador —quién administra cada sitio— no se toca: no se pidió.
 *
 * Idempotente.
 */
#[AsCommand(
    name: 'app:travel:ruta-del-sol-entradas-mer',
    description: 'Quita el boleto de Pukará duplicado y pone a Turismo Mer como comprador de las entradas.',
    hidden: true,
)]
final class RutaDelSolEntradasMerCommand extends Command
{
    private const string VIEJO = 'Ingreso a Museo Litico de Pucará';
    private const string DUPLICADO = 'Ingreso al Museo Lítico de Pukará';

    /** Entradas cuyas tarifas de pago compra Mer. «No necesario» (S/ 0) no se compra. */
    private const array ENTRADAS = ['Ingreso a Andahuaylillas', 'Ingreso a Raqchi', self::VIEJO];

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

        $repo = $this->em->getRepository(TravelComponente::class);
        $viejo = $repo->findOneBy(['nombreInterno' => self::VIEJO]) ?? throw new \RuntimeException('Falta ' . self::VIEJO);
        $mer = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => 'Turismo Mer'])
            ?? throw new \RuntimeException('Falta Turismo Mer');

        // ── El duplicado de Pukará ───────────────────────────────────────────────────
        $duplicado = $repo->findOneBy(['nombreInterno' => self::DUPLICADO]);
        if ($duplicado instanceof TravelComponente) {
            $usos = $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cotizacion_cotcomponente WHERE componente_maestro_id = ?',
                [(string) $duplicado->getId()],
            );
            if (is_numeric($usos) && (int) $usos > 0) {
                $io->error(sprintf('%s lo usan %d cotizaciones: no se borra.', self::DUPLICADO, (int) $usos));

                return Command::FAILURE;
            }

            $general = null;
            foreach ($viejo->getTarifas() as $t) {
                if ($t->getNombreInterno() === 'General') {
                    $general = $t;
                }
            }
            if (!$general instanceof TravelTarifa) {
                $io->error(self::VIEJO . ' no tiene la tarifa «General».');

                return Command::FAILURE;
            }

            foreach ($this->em->getRepository(TravelSegmentoComponente::class)->findBy(['componente' => $duplicado]) as $pivote) {
                $io->text(sprintf('· %s en %s → al boleto viejo', $pivote->getSegmento()?->getSlug(), $pivote->getItinerarioContexto()?->getSlug()));
                if (!$seco) {
                    $pivote->setComponente($viejo)->setTarifaPredeterminada($general);
                }
            }

            $io->text(sprintf('· «%s» — %s', self::DUPLICADO, $seco ? 'se borraría' : 'borrado'));
            if (!$seco) {
                foreach ($this->em->getRepository(TravelServicio::class)->findAll() as $servicio) {
                    if ($servicio->getComponentes()->contains($duplicado)) {
                        $servicio->removeComponente($duplicado);
                        $servicio->addComponente($viejo);
                    }
                }
                foreach ($duplicado->getTarifas()->toArray() as $tarifa) {
                    $this->em->remove($tarifa);
                }
                $this->em->remove($duplicado);
            }
        } else {
            $io->text('· Duplicado de Pukará — ya no existe');
        }

        // ── Mer, comprador de las entradas de pago ───────────────────────────────────
        foreach (self::ENTRADAS as $nombre) {
            $entrada = $repo->findOneBy(['nombreInterno' => $nombre]) ?? throw new \RuntimeException('Falta ' . $nombre);
            foreach ($entrada->getTarifas() as $tarifa) {
                if ((float) $tarifa->getMonto() <= 0.0 || $tarifa->getComprador() === $mer) {
                    continue;
                }
                $io->text(sprintf('· %s · %s S/ %s — comprador Turismo Mer', $nombre, $tarifa->getNombreInterno(), $tarifa->getMonto()));
                if (!$seco) {
                    $tarifa->setComprador($mer);
                }
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
