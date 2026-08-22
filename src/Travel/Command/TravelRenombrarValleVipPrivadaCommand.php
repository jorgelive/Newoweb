<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelItinerario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renombra la privada que decía «tradicional» y en realidad es la VIP.
 *
 * ── Por qué el nombre estaba mal ────────────────────────────────────────────
 * «Full Day Valle sagrado tradicional privado» no lleva logística del Valle tradicional: lleva
 * **Guiado Super Valle + Transporte Super Valle**, que es exactamente la familia que alimenta
 * «Full Day Valle Vip». Es la versión privada del VIP, con el nombre de la otra.
 *
 * Se ve en el precio: Super Valle cuesta entre un 30 % y un 50 % más en todos los tramos —guía
 * 90 frente a 65, Van 115 frente a 89, Bus 250 frente a 180— y su Van lleva dos pasajeros menos.
 * Son dos productos distintos con el mismo itinerario, no dos nombres del mismo.
 *
 * Se queda en el servicio «Valle Sagrado» a propósito: sólo se pidió corregir el nombre, y mover
 * de servicio cambiaría qué segmentos ofrece su pool.
 *
 * ── ⚠️ El título se REGENERA en los siete idiomas ───────────────────────────
 * Y es la parte que puede salir mal en silencio. `AutoTranslationService` sólo pisa una
 * traducción existente si `sobreescribirTraduccion` está activo:
 *
 * ```php
 * if (!$overwrite && !$isContentEmpty) continue;
 * ```
 *
 * Sin activarlo, el español diría «Valle Vip» y los otros seis seguirían diciendo «Sacred
 * Valley» / «Vale Sagrado» — un producto VIP anunciado como el tradicional en todos los idiomas
 * menos uno, y nadie lo mira porque el que revisa lee español. Por eso el comando lo activa antes
 * de tocar el título, y **verifica después** que los seis cambiaron.
 */
#[AsCommand(
    name: 'app:travel:renombrar-valle-vip-privada',
    description: 'Renombra «Valle sagrado tradicional privado» → «Valle Vip privada» y regenera su título.',
)]
final class TravelRenombrarValleVipPrivadaCommand extends Command
{
    private const string NOMBRE_VIEJO = 'Full Day Valle sagrado tradicional privado';
    private const string NOMBRE_NUEVO = 'Full Day Valle Vip privada';
    private const string SLUG_NUEVO = '1D VALLE VIP PRIV';
    private const string TITULO_ES = 'Excursion privada al Valle Vip';

    /** El slug que deja libre el rename, y que le corresponde a la privada del Valle a secas. */
    private const string NOMBRE_PLANA = 'Full Day Valle Sagrado privado';
    private const string SLUG_PLANA = '1D VALLE PRIV';

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
        $repo = $this->em->getRepository(TravelItinerario::class);

        $vip = $repo->findOneBy(['nombreInterno' => self::NOMBRE_VIEJO]);

        if ($vip === null) {
            if ($repo->findOneBy(['nombreInterno' => self::NOMBRE_NUEVO]) !== null) {
                $io->success(sprintf('Ya se llama «%s». Nada que hacer.', self::NOMBRE_NUEVO));

                return Command::SUCCESS;
            }

            $io->error(sprintf('No encuentro «%s».', self::NOMBRE_VIEJO));

            return Command::FAILURE;
        }

        $io->section('Antes');
        $this->pintar($io, $vip);

        $vip->setNombreInterno(self::NOMBRE_NUEVO);
        $vip->setSlug(self::SLUG_NUEVO);
        // El interruptor va ANTES de tocar el título: el listener lo lee en el mismo preUpdate.
        $vip->setSobreescribirTraduccion(true);
        $vip->setTitulo([['language' => 'es', 'content' => self::TITULO_ES]]);

        // El slug limpio queda libre y le toca a la privada del Valle a secas, que nació con uno
        // improvisado hace un rato. Así el juego queda simétrico:
        //   1D VALLE POOL · 1D VALLE PRIV · 1D VALLE VIP POOL · 1D VALLE VIP PRIV
        $plana = $repo->findOneBy(['nombreInterno' => self::NOMBRE_PLANA]);

        if ($plana !== null && $plana->getSlug() !== self::SLUG_PLANA) {
            $io->writeln(sprintf('  <info>·</info> «%s»: slug %s → %s', self::NOMBRE_PLANA, (string) $plana->getSlug(), self::SLUG_PLANA));
            $plana->setSlug(self::SLUG_PLANA);
        }

        if ($seco) {
            $io->newLine();
            $io->success('Nada escrito (--dry-run). El título se regeneraría en los 7 idiomas.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $this->em->refresh($vip);

        $io->section('Después');
        $this->pintar($io, $vip);

        // ── La comprobación que justifica todo lo anterior ──────────────────
        $idiomas = [];

        foreach ($vip->getTitulo() as $fila) {
            $idiomas[(string) ($fila['language'] ?? '?')] = (string) ($fila['content'] ?? '');
        }

        $sinRegenerar = array_keys(array_filter(
            $idiomas,
            static fn (string $texto, string $lang): bool => $lang !== 'es' && stripos($texto, 'vip') === false,
            ARRAY_FILTER_USE_BOTH
        ));

        if (count($idiomas) < 2) {
            $io->warning('El título quedó SÓLO en español: la traducción automática no corrió. Revísalo en el panel.');
        } elseif ($sinRegenerar !== []) {
            $io->warning(sprintf(
                "Estos idiomas no mencionan «VIP» y puede que sigan con el texto viejo: %s\n"
                . 'Compruébalos en el panel antes de que los vea un cliente.',
                implode(', ', $sinRegenerar)
            ));
        } else {
            $io->success(sprintf('Renombrada y título regenerado en %d idiomas.', count($idiomas)));
        }

        return Command::SUCCESS;
    }

    private function pintar(SymfonyStyle $io, TravelItinerario $itinerario): void
    {
        $io->writeln(sprintf('  nombre: %s', (string) $itinerario->getNombreInterno()));
        $io->writeln(sprintf('  slug:   %s', (string) $itinerario->getSlug()));

        foreach ($itinerario->getTitulo() as $fila) {
            $io->writeln(sprintf('    %-4s %s', (string) ($fila['language'] ?? '?'), (string) ($fila['content'] ?? '')));
        }
    }
}
