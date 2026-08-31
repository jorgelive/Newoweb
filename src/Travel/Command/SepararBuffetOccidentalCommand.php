<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * «Restaurante buffet» se parte en sus tres turnos.
 *
 * ── Por qué tres y no uno ───────────────────────────────────────────────────
 * La regla de cuántos servicios de prestador crear es **uno por galería distinta**
 * (`docs/TravelCargaDeCatalogo.md` §4 bis). Un solo «Restaurante buffet» servía a las tres
 * comidas, así que sólo podía ilustrar una: la foto de los huevos revueltos acompañaba
 * también a la cena.
 *
 * ⚠️ **Lo que se gana son las fotos de COMIDA, no las del salón.** Si el resort tiene un único
 * salón buffet, sus fotos serán las mismas en los tres servicios y la deduplicación por URL de
 * `docs/Cotizaciones.md` §6.t las callará a partir del primer día — que es lo correcto. La
 * separación rinde cuando cada turno lleva fotos propias de lo que se sirve.
 *
 * ── Reconvertir + REAPUNTAR, que no es el caso de «El resort» ───────────────
 * Del cajón de sastre colgaba **una** tarifa, así que renombrarlo bastaba. De «Restaurante
 * buffet» cuelgan **tres**, y renombrarlo dejaría a dos apuntando a un servicio que ya no las
 * describe: la tarifa de la cena diría que lo contratado es el desayuno.
 *
 * Por eso aquí hay dos movimientos:
 *
 *   1. el servicio existente se reconvierte en el turno de DESAYUNO — conserva su id, y con él
 *      la tarifa que ya estaba bien y cualquier cotización que la citara;
 *   2. las tarifas de almuerzo y cena **se reapuntan** a los servicios nuevos.
 *
 * Se identifican por su **componente** (`Almuerzo buffet en resort`), no por el nombre de la
 * tarifa: el nombre es prosa y puede cambiar, el componente es el vínculo.
 *
 * ⚠️ **Y hay que tocar `CrearActividadesResortCommand`**, que es idempotente por el nombre de
 * sus servicios de prestador: con `'buffet' => 'Restaurante buffet'` intacto, su siguiente
 * ejecución recrearía el servicio viejo, vacío y en paralelo a los tres. Ya son tres claves.
 * Dos comandos idempotentes que se contradicen dejan de serlo juntos.
 *
 * Por comando y no por SQL: `titulo` y `descripcion` llevan `#[AutoTranslate]`, y aquí se deja
 * encendido porque es contenido publicado.
 *
 * Idempotente por (organización, nombre).
 */
#[AsCommand(
    name: 'app:travel:separar-buffet-occidental',
    description: 'Parte «Restaurante buffet» del Occidental Caribe en desayuno, almuerzo y cena.',
)]
final class SepararBuffetOccidentalCommand extends Command
{
    private const ORG_NOMBRE = 'Occidental Caribe - Punta Cana';
    private const NOMBRE_VIEJO = 'Restaurante buffet';

    /**
     * Los tres turnos. El **primero** hereda el servicio viejo; los otros dos se crean y
     * reciben la tarifa de su componente.
     *
     * @var list<array{nombre: string, titulo: string, descripcion: string, componente: string}>
     */
    private const TURNOS = [
        [
            'nombre' => 'Restaurante - Desayuno Buffet',
            'titulo' => 'Restaurante · Desayuno buffet',
            'descripcion' => 'El buffet del resort en el turno de desayuno, incluido en el todo incluido. '
                . 'Servicio de café, jugos y fruta además de los platos calientes.',
            'componente' => 'Desayuno buffet en resort',
        ],
        [
            'nombre' => 'Restaurante - Almuerzo Buffet',
            'titulo' => 'Restaurante · Almuerzo buffet',
            'descripcion' => 'El buffet del resort en el turno de almuerzo, incluido en el todo incluido, '
                . 'con bebidas incluidas.',
            'componente' => 'Almuerzo buffet en resort',
        ],
        [
            'nombre' => 'Restaurante - Cena Buffet',
            'titulo' => 'Restaurante · Cena buffet',
            'descripcion' => 'El buffet del resort en el turno de cena, incluido en el todo incluido, '
                . 'con bebidas incluidas. Es la alternativa abierta cada noche a los restaurantes '
                . 'temáticos, que requieren reserva.',
            'componente' => 'Cena buffet en resort',
        ],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        if ($simula) {
            $io->note('Simulación: no se escribe nada.');
        }

        $org = $this->em->getRepository(TravelOrganizacion::class)
            ->findOneBy(['nombreComercial' => self::ORG_NOMBRE]);

        if ($org === null) {
            $io->error(sprintf('No existe la organización «%s».', self::ORG_NOMBRE));

            return Command::FAILURE;
        }

        $repoServicio = $this->em->getRepository(TravelOrganizacionServicio::class);
        $primero = self::TURNOS[0];

        // ── 1. El servicio viejo se convierte en el turno de desayuno ───────
        $io->section('El servicio que se parte');
        $viejo = $repoServicio->findOneBy(['organizacion' => $org, 'nombre' => self::NOMBRE_VIEJO]);

        if ($viejo !== null) {
            $io->text(sprintf(
                '  %s · «%s» → «%s»',
                $simula ? 'reconvertiría' : 'reconvertido ',
                self::NOMBRE_VIEJO,
                $primero['nombre']
            ));

            if (!$simula) {
                $viejo->setNombre($primero['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $primero['titulo']]])
                    ->setDescripcion([['language' => 'es', 'content' => $primero['descripcion']]]);
            }
        } else {
            $io->text(sprintf('  no está: «%s» ya se partió en una pasada anterior', self::NOMBRE_VIEJO));
        }

        // ── 2. Los otros dos turnos ─────────────────────────────────────────
        $io->section('Los turnos');
        /** @var array<string, TravelOrganizacionServicio> $porComponente */
        $porComponente = [];

        foreach (self::TURNOS as $i => $turno) {
            $servicio = $repoServicio->findOneBy(['organizacion' => $org, 'nombre' => $turno['nombre']]);

            // El primero no se crea: lo hereda el servicio de arriba. Decir «ya existe» sería
            // mentir en la simulación, donde todavía se llama como se llamaba.
            if ($servicio === null && $i === 0 && $viejo !== null) {
                $io->text(sprintf('  hereda el de arriba · %s', $turno['nombre']));
                $porComponente[$turno['componente']] = $viejo;
                continue;
            }

            if ($servicio !== null) {
                $io->text(sprintf('  ya existe · %s', $turno['nombre']));
                $porComponente[$turno['componente']] = $servicio;
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $turno['nombre']));

            if (!$simula) {
                $servicio = (new TravelOrganizacionServicio())
                    ->setOrganizacion($org)
                    ->setNombre($turno['nombre'])
                    ->setTitulo([['language' => 'es', 'content' => $turno['titulo']]])
                    ->setDescripcion([['language' => 'es', 'content' => $turno['descripcion']]]);
                $this->em->persist($servicio);
                $porComponente[$turno['componente']] = $servicio;
            }
        }

        // ── 3. Reapuntar las tarifas que quedaron en el turno equivocado ────
        // Se buscan por COMPONENTE, no por el nombre de la tarifa: el nombre es prosa y puede
        // reescribirse, el componente es el vínculo.
        $io->section('Tarifas');
        $movidas = 0;

        foreach (self::TURNOS as $turno) {
            $componente = $this->em->getRepository(TravelComponente::class)
                ->findOneBy(['nombreInterno' => $turno['componente']]);

            if ($componente === null) {
                $io->warning(sprintf('No existe el componente «%s»: su tarifa no se reapunta.', $turno['componente']));
                continue;
            }

            $destino = $porComponente[$turno['componente']] ?? null;

            /** @var list<TravelTarifa> $tarifas */
            $tarifas = $this->em->getRepository(TravelTarifa::class)->findBy(['componente' => $componente]);

            foreach ($tarifas as $tarifa) {
                if ($tarifa->getPrestadorServicio() === $destino) {
                    $io->text(sprintf('  ya apunta · %s', $turno['componente']));
                    continue;
                }

                $io->text(sprintf(
                    '  %s · %s → %s',
                    $simula ? 'reapuntaría' : 'reapuntada ',
                    $turno['componente'],
                    $turno['nombre']
                ));
                ++$movidas;

                if (!$simula && $destino !== null) {
                    // La traducción se apaga: mover un id no cambia ningún texto, y un flush
                    // normal dispararía el traductor de la tarifa entera. Ver CLAUDE.md.
                    $tarifa->setEjecutarTraduccion(false);
                    $tarifa->setPrestadorServicio($destino);
                }
            }
        }

        if (!$simula) {
            $io->text('');
            $io->text('Traduciendo los turnos nuevos…');
            $this->em->flush();
        }

        $io->success(sprintf(
            '%sTres turnos y %d tarifa(s) reapuntada(s). Ahora cada comida puede llevar sus propias fotos.',
            $simula ? 'Simulación: ' : '',
            $movidas
        ));

        return Command::SUCCESS;
    }
}
