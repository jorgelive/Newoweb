<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelItinerario;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\ComponenteTipoEnum;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * El contacto con el pasajero en Machu Picchu, como servicio que se pide.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * El guiado de Machu Picchu ocurre **arriba**, en el santuario. El encuentro con el pasajero
 * ocurre en la estación o en su hotel, horas antes y a cuatro horas de distancia. Mientras fueron
 * la misma cosa, el «dónde recojo» de la orden decía uno de los dos y el proveedor iba al otro —
 * con una orden que se lee perfectamente bien.
 *
 * Se descartó estirar el `inicio` del guiado para que significase «dónde se hace contacto»: un
 * campo que quiere decir dos cosas según quién lo lea se rompe la primera vez que alguien lee la
 * que no era. El contacto **es un servicio** —alguien tiene que estar ahí, a una hora, con un
 * cartel— y por tanto es un componente.
 *
 * ── Dos segmentos, no un campo con opciones ─────────────────────────────────
 * «Contacto en la estación» y «Contacto en el hotel» son **dos segmentos**, y la plantilla elige
 * cuál inyecta. Es el mismo mecanismo con el que la variante VIP del Valle Sagrado cambia su
 * segmento final: el segmento dice DÓNDE y el componente dice QUÉ.
 *
 * Y quitarlo del itinerario significa algo concreto y querido: **el pasajero sube por su cuenta y
 * el guía lo espera arriba**.
 *
 * ⚠️ **La tarifa de 0 no es decorativa: sin ella el contacto nunca llega al proveedor.** Un
 * componente sin tarifa es «sólo referencia» —{@see \App\Operacion\Entity\OperacionServicio::isSoloReferencia()}—
 * y **no puede entrar en una Orden de Servicio**: se quedaría visible en La Biblia e invisible
 * para quien tiene que ir a la estación.
 */
#[AsCommand(
    name: 'app:travel:crear-contacto-machupicchu',
    description: 'Crea el componente y los segmentos de contacto con el pasajero, y los engancha a las plantillas de MAPI.',
)]
final class TravelCrearContactoMachupicchuCommand extends Command
{
    private const string COMPONENTE = 'Contacto con el cliente';
    private const string SEG_ESTACION = 'Contacto en la estación de Machu Picchu';
    private const string SEG_HOTEL = 'Contacto en el hotel';
    private const string PUNTO_ESTACION = 'Estación de Machu Picchu';

    /**
     * Dónde entra el contacto en cada plantilla, y cuál de los dos.
     *
     * El reparto lo dictan los datos: donde el grupo **llega en tren esa mañana**, el contacto es
     * en la estación; donde **durmió en Machu Picchu Pueblo**, en su hotel.
     *
     * @var array<string, array{dia: int, orden: int, segmento: string}>
     */
    private const array PLANTILLAS = [
        // Salen del hotel de Cusco de madrugada y llegan en tren: se les recibe en la estación,
        // justo después del tren y antes del bus de subida.
        'Full Day MAPI: CUZ OLLA MAPI OLLA CUZ (bimodal)' => ['dia' => 1, 'orden' => 3, 'segmento' => self::SEG_ESTACION],

        // Durmieron en Machu Picchu Pueblo: el contacto abre el día 2, en su hotel.
        'Two Day MAPI: OLLA MAPI OLLA CUZ (bimodal)' => ['dia' => 2, 'orden' => 1, 'segmento' => self::SEG_HOTEL],
        'Two Day Camino inca' => ['dia' => 2, 'orden' => 1, 'segmento' => self::SEG_HOTEL],
    ];

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

        $componente = $this->componente($io, $seco);
        $segmentos = [
            self::SEG_ESTACION => $this->segmento($io, self::SEG_ESTACION, $seco),
            self::SEG_HOTEL => $this->segmento($io, self::SEG_HOTEL, $seco),
        ];

        if (in_array(null, $segmentos, true) || $componente === null) {
            $io->error('Falta material base; no se engancha nada.');

            return Command::FAILURE;
        }

        foreach ($segmentos as $segmento) {
            $this->colgarComponente($io, $segmento, $componente, $seco);
        }

        $io->section('Plantillas');

        foreach (self::PLANTILLAS as $nombre => $donde) {
            $this->enchufar($io, $nombre, $donde, $segmentos[$donde['segmento']], $seco);
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success($seco ? 'Nada escrito (--dry-run).' : 'Listo. El contacto ya sale en el «dónde recojo» de la orden.');

        return Command::SUCCESS;
    }

    private function componente(SymfonyStyle $io, bool $seco): ?TravelComponente
    {
        $repo = $this->em->getRepository(TravelComponente::class);
        $existente = $repo->findOneBy(['nombre' => self::COMPONENTE]);

        if ($existente !== null) {
            $io->writeln(sprintf('  <comment>·</comment> componente «%s» ya existe', self::COMPONENTE));

            return $existente;
        }

        $componente = new TravelComponente();
        $componente->setNombre(self::COMPONENTE);
        $componente->setTipo(ComponenteTipoEnum::CONTACTO);
        $componente->setTitulo([['language' => 'es', 'content' => 'Recepción y contacto con nuestro personal']]);

        // ⚠️ La tarifa de 0 es lo que lo hace PEDIBLE. Sin ella se queda en «sólo referencia» y no
        // entra en ninguna Orden de Servicio: visible para operaciones, invisible para quien tiene
        // que ir. Hay 217 tarifas de 0 en el catálogo; no es un caso forzado.
        $moneda = $this->em->find(MaestroMoneda::class, 'USD');

        if ($moneda === null) {
            $io->writeln('  <error>✗</error> no encuentro la moneda USD');

            return null;
        }

        $tarifa = new TravelTarifa();
        $tarifa->setComponente($componente);
        $tarifa->setNombreInterno('Base');
        $tarifa->setTitulo([['language' => 'es', 'content' => 'Recepción y contacto']]);
        $tarifa->setMoneda($moneda);
        $tarifa->setMonto('0.00');

        if (!$seco) {
            $this->em->persist($componente);
            $this->em->persist($tarifa);
        }

        $io->writeln(sprintf('  <info>+</info> componente «%s» [contacto] con tarifa Base 0.00 USD', self::COMPONENTE));

        return $componente;
    }

    private function segmento(SymfonyStyle $io, string $nombre, bool $seco): ?TravelSegmento
    {
        $repo = $this->em->getRepository(TravelSegmento::class);
        $existente = $repo->findOneBy(['nombreInterno' => $nombre]);

        if ($existente !== null) {
            $io->writeln(sprintf('  <comment>·</comment> segmento «%s» ya existe', $nombre));

            return $existente;
        }

        $enEstacion = $nombre === self::SEG_ESTACION;
        $segmento = new TravelSegmento();
        $segmento->setNombreInterno($nombre);
        // ⚠️ En la estación se dice EXPRESAMENTE que el encuentro es fuera. Al andén no se
        // entra, y mucha gente espera dentro convencida de que ahí la recogen: es una frase que
        // ahorra una llamada por grupo y una espera con maletas. Ver TravelAjustarTextosContactoCommand.
        $segmento->setTitulo([['language' => 'es', 'content' => $enEstacion
            ? 'Recepción a la salida de la estación de Machu Picchu'
            : 'Encuentro con nuestro personal en tu hotel']]);
        $segmento->setContenido([['language' => 'es', 'content' => $enEstacion
            ? 'Nuestro personal te estará esperando con un cartel a tu nombre en el área de recepción, a la salida de la estación. El andén es de acceso restringido y no se permite el ingreso de acompañantes, así que el encuentro es siempre en el exterior: al bajar del tren, sigue hacia la salida y búscanos allí.'
            : 'Nuestro personal pasará por la recepción de tu hotel a la hora indicada para acompañarte al inicio de la visita. Te recomendamos estar listo unos minutos antes.']]);

        if ($enEstacion) {
            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => self::PUNTO_ESTACION]);

            if ($punto === null) {
                $io->writeln(sprintf('  <error>✗</error> falta el punto «%s»', self::PUNTO_ESTACION));

                return null;
            }

            $segmento->setInicioModo(PuntoModoEnum::FIJO);
            $segmento->setInicioPunto($punto);
        } else {
            $segmento->setInicioModo(PuntoModoEnum::ALOJAMIENTO);
        }

        // Sin fin: el contacto se presenta y ahí acaba su parte — a partir de ahí manda el
        // servicio siguiente. Es lo que dice `PuntosDeServicio::SOLO_INICIO`.
        if (!$seco) {
            $this->em->persist($segmento);
        }

        $io->writeln(sprintf('  <info>+</info> segmento «%s» (inicio: %s)', $nombre,
            $enEstacion ? self::PUNTO_ESTACION : 'el alojamiento del pasajero'));

        return $segmento;
    }

    private function colgarComponente(SymfonyStyle $io, TravelSegmento $segmento, TravelComponente $componente, bool $seco): void
    {
        foreach ($segmento->getSegmentoComponentes() as $existente) {
            if ($existente->getComponente() === $componente) {
                return;
            }
        }

        $pivote = new TravelSegmentoComponente();
        $pivote->setSegmento($segmento);
        $pivote->setComponente($componente);
        $pivote->setModo(ComponenteModoEnum::INCLUIDO);
        $pivote->setOrden(1);

        if (!$seco) {
            $this->em->persist($pivote);
        }

        $io->writeln(sprintf('  <info>+</info> «%s» colgado de «%s»', $componente->getNombre(), $segmento->getNombreInterno()));
    }

    /**
     * Mete el segmento en la plantilla, corriendo los que van detrás.
     *
     * @param array{dia: int, orden: int, segmento: string} $donde
     */
    private function enchufar(SymfonyStyle $io, string $nombrePlantilla, array $donde, TravelSegmento $segmento, bool $seco): void
    {
        $itinerario = $this->em->getRepository(TravelItinerario::class)->findOneBy(['nombreInterno' => $nombrePlantilla]);

        if ($itinerario === null) {
            $io->writeln(sprintf('  <error>✗</error> no encuentro «%s»', $nombrePlantilla));

            return;
        }

        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->createQueryBuilder('r')
            ->andWhere('r.itinerario = :i')->setParameter('i', $itinerario->getId(), UuidType::NAME)
            ->andWhere('r.dia = :d')->setParameter('d', $donde['dia'])
            ->orderBy('r.orden', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($rels as $rel) {
            if ($rel->getSegmento() === $segmento) {
                $io->writeln(sprintf('  <comment>·</comment> %s: ya lo tiene', $nombrePlantilla));

                return;
            }
        }

        // Se corren los de detrás ANTES de meter el nuevo: si se hiciera al revés, dos filas
        // compartirían `orden` durante el mismo flush y el orden de la plantilla quedaría a
        // merced de cómo desempate la base.
        foreach ($rels as $rel) {
            if ($rel->getOrden() >= $donde['orden']) {
                $rel->setOrden($rel->getOrden() + 1);
            }
        }

        $nuevo = new TravelItinerarioSegmentoRel();
        $nuevo->setItinerario($itinerario);
        $nuevo->setSegmento($segmento);
        $nuevo->setDia($donde['dia']);
        $nuevo->setOrden($donde['orden']);

        if (!$seco) {
            $this->em->persist($nuevo);
        }

        $io->writeln(sprintf('  <info>+</info> %-46s día %d, orden %d → «%s»',
            mb_substr($nombrePlantilla, 0, 45), $donde['dia'], $donde['orden'], $segmento->getNombreInterno()));
    }
}
