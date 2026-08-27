<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da de alta los servicios de PeruRail e IncaRail, y corrige el título público de IncaRail.
 *
 * En un tren el «servicio» es la clase, y es justo lo que el pasajero quiere saber: no es lo mismo
 * un Expedition que un Hiram Bingham. Con las dos empresas ya visibles al cliente
 * ({@see \DoctrineMigrations\Version20260828030000}), sin servicios la ficha sólo diría el nombre
 * de la compañía.
 *
 * ⚠️ **Por comando y no por SQL.** `TravelOrganizacionServicio::$titulo` y `$descripcion` llevan
 * `#[AutoTranslate]`, que cuelga de `prePersist`/`preUpdate`: un `INSERT` directo dejaría las
 * fichas **sólo en español** y sin que nada lo denunciara. Lo mismo vale para la errata de
 * IncaRail, que por eso se arregla aquí y no en la migración que las habilitó.
 *
 * Idempotente por (organización, nombre): repetirlo no duplica ni pisa lo que ya exista.
 */
#[AsCommand(
    name: 'app:travel:crear-servicios-ferroviarios',
    description: 'Crea las clases de servicio de PeruRail e IncaRail.',
)]
final class CrearServiciosFerroviariosCommand extends Command
{
    /**
     * Nombres COMERCIALES, tal y como los publican las dos compañías: son productos, y el
     * pasajero los va a reconocer del billete. El `nombre` operativo y el título público
     * coinciden a propósito — aquí no hay jerga interna que traducir.
     *
     * @var array<string, list<string>>
     */
    private const SERVICIOS = [
        'PeruRail' => ['Local', 'Expedition', 'Vistadome', 'Vistadome Observatory', 'Hiram Bingham'],
        'IncaRail' => ['Voyager', 'The 360°', 'Prime', 'First Class'],
    ];

    /** El título público que debería tener cada compañía, por si viene mal escrito. */
    private const TITULOS = ['PeruRail' => 'PeruRail', 'IncaRail' => 'IncaRail'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');
        $repo   = $this->em->getRepository(TravelOrganizacion::class);

        $creados = $existen = $titulos = 0;

        foreach (self::SERVICIOS as $empresa => $clases) {
            $org = $repo->findOneBy(['nombreComercial' => $empresa]);

            if ($org === null) {
                $io->error(sprintf('No existe la organización «%s».', $empresa));

                return Command::FAILURE;
            }

            $io->section($empresa);

            // El título público, si está mal. IncaRail lo tenía como «incaRail».
            $esperado = self::TITULOS[$empresa];
            $actual   = $this->textoEspanol($org->getTitulo());

            if ($actual !== $esperado) {
                $io->text(sprintf('  %s título: «%s» → «%s»', $simula ? 'corregiría' : 'corregido ', $actual ?? '(vacío)', $esperado));

                if (!$simula) {
                    $org->setTitulo([['language' => 'es', 'content' => $esperado]]);
                }

                ++$titulos;
            }

            $yaTiene = [];

            foreach ($org->getServicios() as $s) {
                $yaTiene[(string) $s->getNombre()] = true;
            }

            foreach ($clases as $clase) {
                if (isset($yaTiene[$clase])) {
                    $io->text(sprintf('  ya existe · %s', $clase));
                    ++$existen;
                    continue;
                }

                $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $clase));
                ++$creados;

                if ($simula) {
                    continue;
                }

                $servicio = (new TravelOrganizacionServicio())
                    ->setNombre($clase)
                    ->setTitulo([['language' => 'es', 'content' => $clase]])
                    ->setOrganizacion($org);

                $this->em->persist($servicio);
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->text(sprintf(
            '%s: %d · ya existían: %d · títulos corregidos: %d',
            $simula ? 'Se crearían' : 'Creados',
            $creados,
            $existen,
            $titulos
        ));

        if ($simula) {
            $io->warning('SIMULACRO: no se escribió nada.');

            return Command::SUCCESS;
        }

        $io->success('Listo. Las traducciones las genera AutoTranslate al persistir.');

        return Command::SUCCESS;
    }

    /** @param array<int, array{language?: string, content?: string}> $i18n */
    private function textoEspanol(array $i18n): ?string
    {
        foreach ($i18n as $item) {
            if (($item['language'] ?? '') === 'es') {
                return trim((string) ($item['content'] ?? ''));
            }
        }

        return null;
    }
}
