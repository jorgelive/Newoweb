<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelLugar;
use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelPunto;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Entity\TravelServicio;
use App\Travel\Enum\ComponenteModoEnum;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Enum\PuntoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los terminales propios de Cruz del Sur, y los traslados que van a ellos (23/10/2026).
 *
 * Cruz del Sur NO sale del Terminal Terrestre de CUSCO: usa Av. Industrial 121 (Bancopata). En Puno
 * sí —el jirón 1 de Mayo ES el Terminal Terrestre—, así que el punto y la recepción de Puno que
 * creó este comando se retiraron (`app:travel:retirar-terminal-cruz-del-sur-puno`). Los traslados del catálogo llevaban el destino fijo en el Terminal
 * Terrestre, que es de donde sale Transzela, así que con Cruz del Sur el chofer iba al sitio
 * equivocado.
 *
 * ── Segmento aparte, MISMO componente (Jorge, 23/10/2026) ───────────────────
 *
 * El destino vive en el SEGMENTO (`finPunto`), no en el componente: un segmento no puede tener dos,
 * y corregirlo a mano en cada cotización es el olvido que deja al pasajero en otro terminal. El
 * precio, en cambio, es el mismo —ir a un terminal u otro de la ciudad cuesta igual—, así que los
 * segmentos nuevos cuelgan del componente que ya cobra ese traslado, con sus tarifas, y no se
 * duplica ninguna.
 *
 *   Cusco  hotel → terminal CDS      «Transporte Htl Cusco ↔ Paradero Cusco»   20:00
 *   Cusco  terminal CDS → hotel      el mismo                                   05:00
 *   Puno   terminal CDS → tour       «Transporte Terminal Puno → Hotel de espera»   05:30
 *
 * Las horas son las de los segmentos hermanos del Terminal Terrestre. Los textos dicen sólo lo que
 * se sabe: la recepción de Puno NO promete aseo ni custodia, que el hermano sí dice, porque no
 * consta que el terminal de Cruz del Sur los tenga.
 *
 * De paso, los dos puntos del Terminal Terrestre pasan a tipo «terminal de bus» y se atan a su
 * ciudad; estaban como «otro» y sin lugar. Su dirección no se pone: no hay fuente fiable.
 *
 * Idempotente: puntos por nombre, segmentos por slug.
 */
#[AsCommand(
    name: 'app:travel:terminales-cruz-del-sur',
    description: 'Crea los terminales de Cruz del Sur en Cusco y Puno y los traslados que van a ellos.',
    hidden: true,
)]
final class TerminalesCruzDelSurCommand extends Command
{
    private const string PROVEEDOR = 'Cruz del Sur';

    /** Nombre del punto → [ciudad, dirección]. */
    private const array PUNTOS = [
        'Terminal Cruz del Sur Cusco' => ['Cusco', 'Av. Industrial 121, Urb. Bancopata, Santiago, Cusco'],
    ];

    /** Los del Terminal Terrestre, que sólo se tipan y se atan a su ciudad. */
    private const array TERMINALES_TERRESTRES = [
        'Terminal Terrestre de Cusco' => 'Cusco',
        'Terminal Terrestre de Puno' => 'Puno',
    ];

    /**
     * @var list<array{slug: string, nombre: string, titulo: string, contenido: string, servicio: string,
     *                 componente: string, hora: string, inicio: PuntoModoEnum, fin: PuntoModoEnum,
     *                 inicioPunto: ?string, finPunto: ?string}>
     */
    private const array SEGMENTOS = [
        [
            'slug' => 'TRANS-HTL_CUZ-TERM_CDS_CUZ',
            'nombre' => 'Traslado del hotel al terminal de Cruz del Sur en Cusco',
            'titulo' => 'Al terminal de Cruz del Sur',
            'contenido' => 'Les recogemos en su hotel y les dejamos en el terminal de Cruz del Sur, en la '
                . 'avenida Industrial, con tiempo para el embarque del bus nocturno a Puno. Cruz del Sur '
                . 'tiene terminal propio: no es el Terminal Terrestre.',
            'servicio' => 'TRF_CUZ',
            'componente' => 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)',
            'hora' => '20:00',
            'inicio' => PuntoModoEnum::ALOJAMIENTO,
            'fin' => PuntoModoEnum::FIJO,
            'inicioPunto' => null,
            'finPunto' => 'Terminal Cruz del Sur Cusco',
        ],
        [
            'slug' => 'TRANS-TERM_CDS_CUZ-HTL_CUZ',
            'nombre' => 'Traslado del terminal de Cruz del Sur en Cusco al hotel',
            'titulo' => 'Llegada a Cusco y traslado al hotel',
            'contenido' => 'A la llegada del bus les esperamos en el terminal de Cruz del Sur, en la '
                . 'avenida Industrial, y les llevamos a su hotel. Es de madrugada, así que conviene tener '
                . 'la habitación reservada desde la noche anterior si quieren entrar de inmediato.',
            'servicio' => 'TRF_CUZ',
            'componente' => 'Transporte Htl Cusco ↔ Paradero Cusco (ida o vuelta)',
            'hora' => '05:00',
            'inicio' => PuntoModoEnum::FIJO,
            'fin' => PuntoModoEnum::ALOJAMIENTO,
            'inicioPunto' => 'Terminal Cruz del Sur Cusco',
            'finPunto' => null,
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

        $proveedor = $this->em->getRepository(TravelOrganizacion::class)->findOneBy(['nombreComercial' => self::PROVEEDOR]);
        if (!$proveedor instanceof TravelOrganizacion) {
            $io->error('Falta la organización Cruz del Sur.');

            return Command::FAILURE;
        }

        /** @var array<string, TravelPunto> $puntos */
        $puntos = [];
        foreach (self::PUNTOS as $nombre => [$ciudad, $direccion]) {
            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $nombre]);
            if ($punto instanceof TravelPunto) {
                $io->text(sprintf('· Punto «%s» — ya existe', $nombre));
                $puntos[$nombre] = $punto;
                continue;
            }

            $io->text(sprintf('· Punto «%s» — %s (%s)', $nombre, $seco ? 'se crearía' : 'creado', $direccion));
            $punto = (new TravelPunto())
                ->setNombre($nombre)
                ->setTipo(PuntoTipoEnum::TERMINAL_BUS)
                ->setLugar($this->lugar($ciudad))
                ->setDireccion($direccion)
                ->setOrganizacion($proveedor);
            if (!$seco) {
                $this->em->persist($punto);
            }
            $puntos[$nombre] = $punto;
        }

        foreach (self::TERMINALES_TERRESTRES as $nombre => $ciudad) {
            $punto = $this->em->getRepository(TravelPunto::class)->findOneBy(['nombre' => $nombre]);
            if (!$punto instanceof TravelPunto) {
                continue;
            }
            if ($punto->getTipo() !== PuntoTipoEnum::TERMINAL_BUS || $punto->getLugar() === null) {
                $io->text(sprintf('· Punto «%s» — %s terminal de bus en %s', $nombre, $seco ? 'pasaría a' : 'pasa a', $ciudad));
                if (!$seco) {
                    $punto->setTipo(PuntoTipoEnum::TERMINAL_BUS)->setLugar($punto->getLugar() ?? $this->lugar($ciudad));
                }
            }
        }

        foreach (self::SEGMENTOS as $def) {
            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']]) !== null) {
                $io->text(sprintf('· Segmento %s — ya existe', $def['slug']));
                continue;
            }

            $servicio = $this->em->getRepository(TravelServicio::class)->findOneBy(['codigo' => $def['servicio']]);
            $componente = $this->em->getRepository(TravelComponente::class)->findOneBy(['nombreInterno' => $def['componente']]);
            if (!$servicio instanceof TravelServicio || !$componente instanceof TravelComponente) {
                $io->error(sprintf('Falta el servicio %s o el componente «%s».', $def['servicio'], $def['componente']));

                return Command::FAILURE;
            }

            $io->text(sprintf('· Segmento %s — %s, en %s, %s', $def['slug'], $seco ? 'se crearía' : 'creado', $def['servicio'], $def['hora']));
            if ($seco) {
                continue;
            }

            $segmento = (new TravelSegmento())
                ->setSlug($def['slug'])
                ->setNombreInterno($def['nombre'])
                ->setTitulo([['language' => 'es', 'content' => $def['titulo']]])
                ->setContenido([['language' => 'es', 'content' => $def['contenido']]])
                ->setInicioModo($def['inicio'])
                ->setFinModo($def['fin']);
            if ($def['inicioPunto'] !== null) {
                $segmento->setInicioPunto($puntos[$def['inicioPunto']]);
            }
            if ($def['finPunto'] !== null) {
                $segmento->setFinPunto($puntos[$def['finPunto']]);
            }
            $this->em->persist($segmento);
            $servicio->addSegmento($segmento);

            // Global, como el de su hermano del Terminal Terrestre: sin plantilla ni día.
            $this->em->persist((new TravelSegmentoComponente())
                ->setSegmento($segmento)
                ->setComponente($componente)
                ->setModo(ComponenteModoEnum::INCLUIDO)
                ->setOrden(1)
                ->setHora(new \DateTimeImmutable($def['hora'])));
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success($seco ? 'Simulacro: no se escribió nada.' : 'Hecho.');

        return Command::SUCCESS;
    }

    private function lugar(string $nombre): TravelLugar
    {
        return $this->em->getRepository(TravelLugar::class)->findOneBy(['nombre' => $nombre])
            ?? throw new \RuntimeException(sprintf('No existe el lugar «%s».', $nombre));
    }
}
