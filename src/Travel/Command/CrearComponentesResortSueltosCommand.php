<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelOrganizacionServicio;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Entity\TravelTarifa;
use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le pone componente a los segmentos de resort que se crearon a mano por el panel.
 *
 * ── Por qué existe, en vez de meterlos en el comando grande ─────────────────
 * `app:travel:crear-actividades-resort` es idempotente **por el slug del segmento**: si el
 * segmento ya está, hace `continue` y nunca llega a su componente ni a su tarifa. Es lo correcto
 * para lo que hace —montar la cadena entera desde cero—, pero convierte en imposible el caso de
 * hoy: **los segmentos ya existen, escritos en el panel, y lo que falta es lo que cuelga de
 * ellos**. Meter aquí un `if` para distinguir los dos casos habría hecho que el comando grande
 * dejara de ser leíble de una pasada.
 *
 * Ver `docs/TravelCargaDeCatalogo.md` §4 bis, «el `continue` del paso 1»: ese atajo es lo que
 * hace idempotente todo lo que cuelga, y tiene justamente este filo.
 *
 * ── Un segmento sin componente no se puede vender ───────────────────────────
 * Se guarda sin quejarse y se puede arrastrar a un día, pero no aporta nada que cobrar ni nada
 * que despachar: es el fallo más común al cargar catálogo a mano y no da ningún error. Estos dos
 * llevaban 0 componentes.
 *
 * ── La hora va con FIN ──────────────────────────────────────────────────────
 * ⚠️ Sus hermanos sólo tienen hora de inicio y por eso nacen con duración cero. Aquí hay franja
 * real —19:00-22:00 y 11:00-13:30— así que se pone también `horaFin`: sin ella el bloque no ocupa
 * sitio en el día y la guía lo coloca como un instante. Ver `docs/TravelCargaDeCatalogo.md` §5.
 *
 * Idempotente: si el segmento ya tiene componente, no toca nada.
 */
#[AsCommand(
    name: 'app:travel:crear-componentes-resort-sueltos',
    description: 'Crea componente, tarifa y enlace para los segmentos de resort que se hicieron a mano.',
)]
final class CrearComponentesResortSueltosCommand extends Command
{
    private const ORG_NOMBRE = 'Occidental Caribe - Punta Cana';
    private const SERVICIO_CODIGO = 'ACT_RESORT';
    private const MONEDA = 'USD';

    /**
     * ⚠️ `prestador` es el nombre del `TravelOrganizacionServicio` del que saldrán las FOTOS.
     * Se reusan los que ya existen en vez de crear dos nuevos: la regla es **uno por galería
     * distinta**, y hasta que haya fotos propias de la discoteca o de las olimpiadas, un servicio
     * más sólo añadiría un buzón vacío. Cuando las haya, se crea el suyo y se repunta la tarifa
     * —es una línea— sin tocar nada más.
     *
     * @var list<array{slug: string, tipo: ComponenteTipoEnum, prestador: string, hora: string, horaFin: string}>
     */
    private const DEFINICIONES = [
        [
            'slug' => 'ACT-RESORT-OLIMPIADAS',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'Actividades y deportes',
            'hora' => '11:00',
            'horaFin' => '13:30',
        ],
        [
            'slug' => 'ACT-RESORT-DISCO_PRIVADA',
            'tipo' => ComponenteTipoEnum::EXTRAS,
            'prestador' => 'Espectáculos nocturnos',
            'hora' => '19:00',
            'horaFin' => '22:00',
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
        $moneda = $this->em->getRepository(MaestroMoneda::class)->find(self::MONEDA);
        $servicioTravel = $this->em->getRepository(TravelServicio::class)
            ->findOneBy(['codigo' => self::SERVICIO_CODIGO]);

        if ($org === null || $moneda === null) {
            $io->error('Falta la organización o la moneda: corre antes `app:travel:crear-actividades-resort`.');

            return Command::FAILURE;
        }

        $io->section('Componentes que faltaban');
        $creados = 0;

        foreach (self::DEFINICIONES as $def) {
            $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]);

            if ($segmento === null) {
                $io->warning(sprintf('No existe el segmento «%s»: se salta.', $def['slug']));
                continue;
            }

            // La clave de idempotencia es el ENLACE, no el componente: lo que falta es que el
            // segmento tenga algo colgando, y comprobarlo aquí evita crear un segundo componente
            // si el comando se repite.
            if (!$segmento->getSegmentoComponentes()->isEmpty()) {
                $io->text(sprintf('  ya tiene componente · %s', $def['slug']));
                continue;
            }

            $nombre = (string) $segmento->getNombreInterno();
            $servicioPrestador = $this->em->getRepository(TravelOrganizacionServicio::class)
                ->findOneBy(['organizacion' => $org, 'nombre' => $def['prestador']]);

            $io->text(sprintf(
                '  %s · %-26s %-36s %s-%s → %s',
                $simula ? 'crearía' : 'creado ',
                $def['slug'],
                $nombre,
                $def['hora'],
                $def['horaFin'],
                $def['prestador'],
            ));

            ++$creados;

            if ($simula) {
                continue;
            }

            // El título público sale del SEGMENTO, que ya lo tiene traducido a siete idiomas
            // porque se escribió por el panel. Copiarlo es lo que evita que el componente nazca
            // sólo en español y que alguien tenga que reescribirlo.
            $titulo = $segmento->getTitulo();

            $componente = (new TravelComponente())
                ->setNombreInterno($nombre)
                ->setTitulo($titulo)
                ->setTipo($def['tipo']);
            $this->em->persist($componente);

            // Los setters de TravelTarifa devuelven void: no se encadenan.
            $tarifa = new TravelTarifa();
            $tarifa->setComponente($componente);
            $tarifa->setNombreInterno(sprintf('%s · %s', self::ORG_NOMBRE, $nombre));
            $tarifa->setTitulo($titulo);
            $tarifa->setMoneda($moneda);
            $tarifa->setMonto('0.00');
            $tarifa->setPrestador($org);
            $tarifa->setPrestadorServicio($servicioPrestador);
            $this->em->persist($tarifa);

            $this->em->persist(
                (new TravelSegmentoComponente())
                    ->setSegmento($segmento)
                    ->setComponente($componente)
                    ->setTarifaPredeterminada($tarifa)
                    ->setModo(ComponenteModoEnum::INCLUIDO)
                    ->setDia(1)
                    ->setOrden(1)
                    ->setHora(new \DateTimeImmutable($def['hora']))
                    ->setHoraFin(new \DateTimeImmutable($def['horaFin']))
            );

            // Los DOS pools. El de componentes es el que se olvida, y sin él el párrafo se
            // arrastra a un día sin nada que cobrar.
            if ($servicioTravel !== null) {
                $servicioTravel->addSegmento($segmento);
                $servicioTravel->addComponente($componente);
            }
        }

        if (!$simula) {
            $io->text('');
            $io->text('Traduciendo los títulos nuevos…');
            $this->em->flush();
        }

        $io->success(sprintf('%s%d componente(s) con su tarifa y su enlace.', $simula ? 'Simulación: ' : '', $creados));

        return Command::SUCCESS;
    }
}
