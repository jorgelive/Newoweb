<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * «Por cuenta del cliente»: un componente genérico que sólo pone HORA, y el primer tramo que lo usa
 * (23/10/2026).
 *
 * ── El tramo ────────────────────────────────────────────────────────────────
 *
 * Al volver de la excursión del Titicaca no hay traslado al terminal: el pasajero tiene la tarde
 * libre en el centro de Puno y va al bus de las 22:00 por su cuenta. Jorge: desde la Plaza de Armas
 * al Terminal Terrestre hay Uber y mototaxi —menos de 10 minutos, menos de S/10— o 20 minutos a pie,
 * y tienen que salir a las 20:30 «para que no se les pase». Un segmento por terminal, como los demás
 * traslados: el de Cruz del Sur sin minutos, porque no se sabe la distancia.
 *
 * ── Por qué hace falta un componente ────────────────────────────────────────
 *
 * La hora vive en el PIVOTE (`docs/TravelCargaDeCatalogo.md`, «La hora vive en el pivote»): un
 * segmento sin componente no tiene hora, y el itinerario lo habría colocado con la hora nominal —por
 * la mañana, antes del tour—. «Por cuenta del cliente» sirve para eso en cualquier sitio:
 *
 * - **`NO_INCLUIDO` y sin tarifa**: no es costo, no se cobra y no entra en ninguna orden de
 *   servicio (`OperacionServicio::isSoloReferencia()`). En la propuesta sale como «No incluye: Por
 *   cuenta del cliente», que es exactamente lo que es.
 * - **Tipo `actividad_fijo`**: el único cuya razón de ser es que la hora es el dato.
 * - **En el pool de todos los contenedores genéricos**, para engancharlo donde haga falta.
 */
#[AsCommand(
    name: 'app:travel:puno-al-terminal-por-cuenta',
    description: 'Crea el componente «Por cuenta del cliente» y el tramo del centro de Puno al terminal.',
    hidden: true,
)]
final class PunoAlTerminalPorCuentaCommand extends Command
{
    private const string COMPONENTE = 'Por cuenta del cliente';
    private const string SERVICIO = 'TRF_PUN';
    private const string HORA = '20:30';

    /** @var list<array{slug: string, nombre: string, titulo: string, contenido: string, punto: string}> */
    private const array SEGMENTOS = [
        [
            'slug' => 'TRANS-CENTRO_PUN-TERM_PUN',
            'nombre' => 'Del centro de Puno al Terminal Terrestre, por su cuenta',
            'titulo' => 'Al terminal, por su cuenta',
            'contenido' => 'Después de la excursión tienen la tarde libre para pasear y cenar en el centro de '
                . 'Puno. Hacia las 20:30 salgan al Terminal Terrestre, también por su cuenta: desde la Plaza de '
                . 'Armas son menos de 10 minutos en Uber o en mototaxi —el tuk tuk de aquí—, por menos de S/10, '
                . 'o unos 20 minutos caminando.',
            'punto' => 'Terminal Terrestre de Puno',
        ],
        [
            'slug' => 'TRANS-CENTRO_PUN-TERM_CDS_PUN',
            'nombre' => 'Del centro de Puno al terminal de Cruz del Sur, por su cuenta',
            'titulo' => 'Al terminal, por su cuenta',
            'contenido' => 'Después de la excursión tienen la tarde libre para pasear y cenar en el centro de '
                . 'Puno. Hacia las 20:30 salgan al terminal de Cruz del Sur, en el jirón 1 de Mayo, también por '
                . 'su cuenta: hay Uber y mototaxis —el tuk tuk de aquí— desde la Plaza de Armas.',
            'punto' => 'Terminal Cruz del Sur Puno',
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
        $seco = (bool) $input->getOption('dry-run');

        $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => self::COMPONENTE]);
        if (!$componente instanceof TravelComponente) {
            $io->text(sprintf('· Componente «%s» — %s', self::COMPONENTE, $seco ? 'se crearía' : 'creado'));
            $componente = (new TravelComponente())
                ->setNombreInterno(self::COMPONENTE)
                ->setTitulo([['language' => 'es', 'content' => self::COMPONENTE]])
                ->setTipo(ComponenteTipoEnum::ACTIVIDAD_HORARIO_FIJO);
            if (!$seco) {
                $this->em->persist($componente);
            }
        }

        $contenedores = $this->em->getRepository(TravelServicio::class)->findBy(['contenedorGenerico' => true]);
        foreach ($contenedores as $contenedor) {
            if (!$contenedor->getComponentes()->contains($componente)) {
                $io->text(sprintf('· Al pool de %s', $contenedor->getCodigo()));
                if (!$seco) {
                    $contenedor->addComponente($componente);
                }
            }
        }

        $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => self::SERVICIO])
            ?? throw new \RuntimeException('No existe ' . self::SERVICIO);

        foreach (self::SEGMENTOS as $def) {
            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('· %s — ya existe', $def['slug']));
                continue;
            }

            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $def['punto']])
                ?? throw new \RuntimeException('No existe el punto ' . $def['punto']);

            $io->text(sprintf('· %s — %s, en %s, %s', $def['slug'], $seco ? 'se crearía' : 'creado', self::SERVICIO, self::HORA));
            if ($seco) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]])
                ->setInicioModo(PuntoModoEnum::SIN_DEFINIR)
                ->setFinModo(PuntoModoEnum::FIJO)
                ->setFinPunto($punto);
            $this->em->persist($segmento);
            $servicio->addSegmento($segmento);

            // Global y sin tarifa: aporta la hora y nada más.
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setModo(ComponenteModoEnum::NO_INCLUIDO)
                ->setOrden(1)
                ->setHora(new \DateTimeImmutable(self::HORA)));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
