<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelItinerarioSegmentoRel;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Los traslados bimodales llevaban DOS transportes en un segmento, y colisionaban por diseño.
 *
 * ── El síntoma ──────────────────────────────────────────────────────────────
 *
 * Un traslado bimodal son dos servicios: la movilidad que recoge en el hotel y deja en
 * Wanchaq o en Av. El Sol, y el bus que de ahí lleva a la estación. Estaban colgados del mismo
 * segmento, y como los dos son `TRANSPORTE`, {@see ComponenteTipoEnum::mandaElSegmento()} pone
 * arriba el segmento — el mismo para los dos. En la Orden de Servicio salía así:
 *
 * ```
 * Traslado Cusco - Ollantaytambo (Servicio bimodal)
 *   Transporte Wanchaq/Sol ↔ Hotel (ida o vuelta)
 *
 * Traslado Cusco - Ollantaytambo (Servicio bimodal)
 *   Bus Bimodal Cusco ↔ Ollantatyambo (ida o vuelta)
 * ```
 *
 * Dos encargos que parecen el mismo repetido, y lo que los distingue —cuál es el bus y cuál la
 * movilidad interna— relegado a la línea pequeña, con una flecha de dos puntas.
 *
 * ── Por qué NO se arregla con el tipo ───────────────────────────────────────
 *
 * `TRANSPORTE_EXCURSION` parece la salida porque {@see ComponenteTipoEnum::ocultaElSegmento()}
 * sube el componente. Pero entonces `getSecundarioParaProveedor()` devuelve **null** y el
 * segmento no baja: desaparece. El proveedor leería «Bus Bimodal Cusco ↔ Ollantatyambo (ida o
 * vuelta)» y nada más, sin saber si hoy es ida o vuelta — el fallo exacto que cerró el refactor
 * del 29/08/2026 al poner el segmento arriba. Cambia una colisión cosmética por una pérdida real.
 *
 * ── La corrección: un segmento por tramo ────────────────────────────────────
 *
 * El segmento es lo que identifica un transporte; dos transportes en uno colisionan por
 * definición. Y son dos servicios de verdad: a horas distintas, desde sitios distintos y los
 * puede prestar gente distinta.
 *
 * ⚠️ **El componente no se toca.** Sigue siendo bidireccional «(ida o vuelta)» con sus tarifas
 * donde están: lo que se parte es el segmento, que no cuesta nada. Es lo que ya hace el resto
 * del catálogo —`Transporte Aeropuerto Cusco ↔ Cusco` tiene un segmento por sentido— y evita
 * multiplicar tarifas, que era el reparo.
 *
 * ⚠️ **El slug viejo se queda con el tramo del BUS**, no se renombra. Los `rel` apuntan por id,
 * así que renombrarlo no rompería nada, pero sí dejaría el comando sin clave por la que
 * reconocer lo ya hecho.
 *
 * ⚠️ **El extremo de Wanchaq va `SIN_DEFINIR` y sin punto nuevo.** El componente dice
 * «Wanchaq/Sol»: son dos sitios y el operador usa uno u otro según el día. Un punto fijo
 * afirmaría de más, y los puntos sin dirección «no sirven» (§9 de `docs/Travel.md`). El dato
 * vive en el nombre del segmento, que es justo lo que esta corrección viene a arreglar.
 */
#[AsCommand(
    name: 'app:travel:partir-segmentos-bimodales',
    description: 'Parte cada traslado bimodal en dos segmentos: la movilidad interna y el bus.',
    hidden: true,
)]
final class PartirSegmentosBimodalesCommand extends Command
{
    /**
     * Un bimodal por fila.
     *
     * `interno` es el componente que se muda al segmento nuevo; el otro se queda en el viejo.
     * `antes` dice si el tramo interno ocurre antes del bus (ida) o después (retorno), que es
     * lo que decide dónde entra en la plantilla.
     *
     * @var list<array{viejo: string, interno: string, nuevoSlug: string, nuevoNombre: string,
     *                 nuevoTitulo: string, nuevoContenido: string, antes: bool,
     *                 busNombre: string, busTitulo: string, busContenido: string}>
     */
    private const BIMODALES = [
        [
            'viejo' => 'TRANS_BIM_IDA-MAPI-CUZ_OLL',
            'interno' => 'Transporte Wanchaq/Sol ↔ Hotel (ida o vuelta)',
            'nuevoSlug' => 'TRANS_BIM_IDA-MAPI-HTL_WAN_OLL',
            'nuevoNombre' => 'Traslado del hotel a Wanchaq / Av. El Sol (bimodal Ollanta)',
            'nuevoTitulo' => 'Del hotel al punto de salida del bus',
            'nuevoContenido' => 'Les recogemos en su hotel de madrugada y les llevamos al punto de '
                . 'salida del servicio bimodal, en Wanchaq o en Av. El Sol según el día.',
            'antes' => true,
            'busNombre' => 'Bus bimodal a Ollantaytambo',
            'busTitulo' => 'Bus bimodal hasta Ollantaytambo',
            'busContenido' => 'Tramo en bus hasta la estación de Ollantaytambo, donde se enlaza con '
                . 'el tren. Es la mitad por carretera del servicio bimodal.',
        ],
        [
            'viejo' => 'TRANS_BIM_RET-MAPI-OLL_CUZ',
            'interno' => 'Transporte Wanchaq/Sol ↔ Hotel (ida o vuelta)',
            'nuevoSlug' => 'TRANS_BIM_RET-MAPI-WAN_HTL_OLL',
            'nuevoNombre' => 'Traslado de Wanchaq / Av. El Sol al hotel (bimodal Ollanta)',
            'nuevoTitulo' => 'Del punto de llegada del bus al hotel',
            'nuevoContenido' => 'A la llegada del bus les esperamos en Wanchaq o en Av. El Sol y les '
                . 'llevamos a su hotel.',
            'antes' => false,
            'busNombre' => 'Bus bimodal desde Ollantaytambo',
            'busTitulo' => 'Bus bimodal desde Ollantaytambo',
            'busContenido' => 'Tramo en bus desde la estación de Ollantaytambo de vuelta a Cusco, '
                . 'enlazando con el tren.',
        ],
        [
            'viejo' => 'TRANS_BIM_IDA-MAPI-CUZ_POR',
            'interno' => 'Transporte Wanchaq/Sol ↔ Hotel (ida o vuelta)',
            'nuevoSlug' => 'TRANS_BIM_IDA-MAPI-HTL_WAN_POR',
            'nuevoNombre' => 'Traslado del hotel a Wanchaq / Av. El Sol (bimodal Poroy)',
            'nuevoTitulo' => 'Del hotel al punto de salida del bus',
            'nuevoContenido' => 'Les recogemos en su hotel y les llevamos al punto de salida del '
                . 'servicio bimodal, en Wanchaq o en Av. El Sol según el día.',
            'antes' => true,
            'busNombre' => 'Bus bimodal a Poroy',
            'busTitulo' => 'Bus bimodal hasta Poroy',
            'busContenido' => 'Tramo en bus hasta la estación de Poroy, donde se enlaza con el tren.',
        ],
        [
            // Estaba apuntado a «Transporte Cusco ↔ Poroy», que era un error: en bimodal el bus
            // devuelve a Wanchaq y de ahí la movilidad lleva al hotel, igual que en los otros
            // tres. La partición usa el componente que la fila tenía de hecho; corregirlo es
            // {@see self::CORRECCIONES}, que corre después.
            'viejo' => 'TRANS_BIM_RET-MAPI-POR_CUZ',
            'interno' => 'Transporte Cusco ↔ Poroy (ida o vuelta)',
            'nuevoSlug' => 'TRANS_BIM_RET-MAPI-POR_HTL',
            'nuevoNombre' => 'Traslado de Poroy al hotel (bimodal Poroy)',
            'nuevoTitulo' => 'De la llegada del bus al hotel',
            'nuevoContenido' => 'Traslado hasta su hotel al terminar el tramo en bus.',
            'antes' => false,
            'busNombre' => 'Bus bimodal desde Poroy',
            'busTitulo' => 'Bus bimodal desde Poroy',
            'busContenido' => 'Tramo en bus desde la estación de Poroy de vuelta a Cusco, enlazando '
                . 'con el tren.',
        ],
    ];

    /**
     * El texto de los ocho tramos, con la regla del operador dentro.
     *
     * ⚠️ **El punto intermedio NO se puede fijar en el segmento**, y por eso se cuenta aquí. Lo
     * decide la ferroviaria —PeruRail sale de Wanchaq, IncaRail de su oficina de la avenida El
     * Sol— y la ferroviaria se elige al COTIZAR, con la tarifa del tren. Las plantillas de MAPI
     * sirven a las dos, así que cualquier punto fijo mentiría la mitad de las veces y dos
     * segmentos, uno por operador, inyectarían los dos.
     *
     * Lo que sí se puede es escribir la regla, de modo que quien lea la orden sepa dónde recoger
     * sin preguntar. Es el mismo criterio de «la guía informa»: el texto dice la política entera.
     *
     * ⚠️ **IncaRail no opera hacia Poroy**, y es la única combinación que no existe. Va dicho
     * explícito en los dos tramos de Poroy porque nadie lo adivina.
     *
     * @var array<string, array{titulo: string, contenido: string}>
     */
    private const TEXTOS = [
        'TRANS_BIM_IDA-MAPI-HTL_WAN_OLL' => [
            'titulo' => 'Del hotel al punto de salida del bus',
            'contenido' => 'Les recogemos en su hotel de madrugada y les llevamos al punto de salida '
                . 'del servicio bimodal. Con PeruRail es la estación de Wanchaq; con IncaRail, su '
                . 'oficina de la avenida El Sol.',
        ],
        'TRANS_BIM_IDA-MAPI-CUZ_OLL' => [
            'titulo' => 'Bus bimodal hasta Ollantaytambo',
            'contenido' => 'Tramo por carretera hasta la estación de Ollantaytambo, donde se enlaza '
                . 'con el tren. Sale de Wanchaq si viaja con PeruRail y de la avenida El Sol si viaja '
                . 'con IncaRail. El bus va incluido en el boleto del tren.',
        ],
        'TRANS_BIM_IDA-MAPI-HTL_WAN_POR' => [
            'titulo' => 'Del hotel a la estación de Wanchaq',
            'contenido' => 'Les recogemos en su hotel y les llevamos a la estación de Wanchaq, de '
                . 'donde sale el bus. Esta ruta es sólo de PeruRail: IncaRail no opera hacia Poroy.',
        ],
        'TRANS_BIM_IDA-MAPI-CUZ_POR' => [
            'titulo' => 'Bus bimodal hasta Poroy',
            'contenido' => 'Tramo por carretera de Wanchaq a la estación de Poroy, donde se enlaza '
                . 'con el tren. Sólo PeruRail: IncaRail no opera hacia Poroy. El bus va incluido en '
                . 'el boleto del tren.',
        ],
        'TRANS_BIM_RET-MAPI-OLL_CUZ' => [
            'titulo' => 'Bus bimodal desde Ollantaytambo',
            'contenido' => 'Tramo por carretera desde la estación de Ollantaytambo de vuelta a Cusco. '
                . 'Deja en Wanchaq si viaja con PeruRail y en la avenida El Sol si viaja con IncaRail. '
                . 'Va incluido en el boleto del tren.',
        ],
        'TRANS_BIM_RET-MAPI-WAN_HTL_OLL' => [
            'titulo' => 'Del punto de llegada del bus al hotel',
            'contenido' => 'A la llegada del bus les esperamos y les llevamos a su hotel. Con PeruRail '
                . 'el bus deja en la estación de Wanchaq; con IncaRail, en su oficina de la avenida '
                . 'El Sol.',
        ],
        'TRANS_BIM_RET-MAPI-POR_CUZ' => [
            'titulo' => 'Bus bimodal desde Poroy',
            'contenido' => 'Tramo por carretera desde la estación de Poroy de vuelta a Cusco. Sólo '
                . 'PeruRail: IncaRail no opera hacia Poroy. Va incluido en el boleto del tren.',
        ],
        'TRANS_BIM_RET-MAPI-POR_HTL' => [
            'titulo' => 'De la estación de Wanchaq al hotel',
            'contenido' => 'El bus deja en la estación de Wanchaq, donde les esperamos para '
                . 'llevarles a su hotel. Esta ruta es sólo de PeruRail: IncaRail no opera hacia Poroy.',
        ],
    ];

    /**
     * Componentes mal apuntados, por segmento. `de` es lo que hay; `a`, lo que debería ser.
     *
     * ⚠️ El retorno bimodal de Poroy colgaba de «Transporte Cusco ↔ Poroy», que es el traslado
     * directo a la estación — otro servicio. En bimodal el bus devuelve a **Wanchaq** y de ahí
     * la movilidad lleva al hotel, que es lo mismo que hacen las rutas de Ollantaytambo.
     * Confirmado con el operador el 09/10/2026.
     *
     * Se reapunta el pivote en vez de crear otro: la hora, el modo y el contexto ya están bien.
     *
     * @var array<string, array{de: string, a: string}>
     */
    private const CORRECCIONES = [
        'TRANS_BIM_RET-MAPI-POR_HTL' => [
            'de' => 'Transporte Cusco ↔ Poroy (ida o vuelta)',
            'a' => 'Transporte Wanchaq/Sol ↔ Hotel (ida o vuelta)',
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
        $simula = (bool) $input->getOption('dry-run');

        $io->title('Traslados bimodales: un segmento por tramo');
        $partidos = 0;

        foreach (self::BIMODALES as $def) {
            $io->section($def['viejo']);

            if ($this->segmento($def['nuevoSlug']) !== null) {
                $io->text('  ya partido.');
                continue;
            }

            $viejo = $this->segmento($def['viejo']);

            if ($viejo === null) {
                $io->text('  ⚠ no existe, se salta.');
                continue;
            }

            /** @var list<TravelSegmentoComponente> $pivotes */
            $pivotes = $this->em->getRepository(TravelSegmentoComponente::class)
                ->findBy(['segmento' => $viejo]);

            $mudan = array_values(array_filter(
                $pivotes,
                static fn (TravelSegmentoComponente $p): bool => $p->getComponente()?->getNombreInterno() === $def['interno'],
            ));

            if ($mudan === []) {
                $io->text(sprintf('  ⚠ no tiene «%s», se salta.', $def['interno']));
                continue;
            }

            ++$partidos;

            $io->text(sprintf('  %s · segmento nuevo  %s', $simula ? 'haría ' : 'hecho ', $def['nuevoSlug']));
            $io->text(sprintf('  %s · le mudan %d pivote(s) de «%s»', $simula ? 'haría ' : 'hecho ', count($mudan), $def['interno']));
            $io->text(sprintf('  %s · el viejo pasa a llamarse «%s»', $simula ? 'haría ' : 'hecho ', $def['busNombre']));

            if (!$simula) {
                $nuevo = (new TravelSegmento())
                    ->setSlug($def['nuevoSlug'])
                    ->setNombreInterno($def['nuevoNombre'])
                    ->setTitulo([['language' => 'es', 'content' => $def['nuevoTitulo']]])
                    ->setContenido([['language' => 'es', 'content' => $def['nuevoContenido']]])
                    ->setInicioModo($def['antes'] ? PuntoModoEnum::ALOJAMIENTO : PuntoModoEnum::SIN_DEFINIR)
                    ->setFinModo($def['antes'] ? PuntoModoEnum::SIN_DEFINIR : PuntoModoEnum::ALOJAMIENTO);

                $this->em->persist($nuevo);

                foreach ($viejo->getServicios() as $servicio) {
                    $nuevo->addServicio($servicio);
                }

                foreach ($mudan as $pivote) {
                    $pivote->setSegmento($nuevo);
                }

                // El viejo se queda SOLO con el bus, así que ya no empieza ni acaba en el hotel:
                // ese extremo es ahora del segmento nuevo.
                $viejo->setSobreescribirTraduccion(true);
                $viejo->setNombreInterno($def['busNombre']);
                $viejo->setTitulo([['language' => 'es', 'content' => $def['busTitulo']]]);
                $viejo->setContenido([['language' => 'es', 'content' => $def['busContenido']]]);

                if ($def['antes']) {
                    $viejo->setInicioModo(PuntoModoEnum::SIN_DEFINIR);
                    $viejo->setInicioPunto(null);
                } else {
                    $viejo->setFinModo(PuntoModoEnum::SIN_DEFINIR);
                    $viejo->setFinPunto(null);
                }

                $this->em->flush();
            }

            $this->insertarEnPlantillas($io, $viejo, $def, $simula);
        }

        if (!$simula) {
            $this->em->flush();
        }

        // Los textos, en pasada aparte: la partición es idempotente por el slug nuevo, así que
        // en la segunda pasada el bucle de arriba no entra y una corrección de texto no llegaría
        // nunca. Aquí se compara contra lo que hay, que es lo que de verdad decide.
        $io->section('Componentes mal apuntados');
        $reapuntados = 0;

        foreach (self::CORRECCIONES as $slug => $cambio) {
            $segmento = $this->segmento($slug);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $slug));
                continue;
            }

            /** @var list<TravelSegmentoComponente> $pivotes */
            $pivotes = $this->em->getRepository(TravelSegmentoComponente::class)
                ->findBy(['segmento' => $segmento]);

            $bueno = $this->em->getRepository(\App\Travel\Entity\TravelComponente::class)
                ->findOneBy(['nombreInterno' => $cambio['a']]);

            if ($bueno === null) {
                $io->error(sprintf('  no existe el componente «%s».', $cambio['a']));

                return Command::FAILURE;
            }

            $tocado = false;

            foreach ($pivotes as $pivote) {
                if ($pivote->getComponente()?->getNombreInterno() !== $cambio['de']) {
                    continue;
                }

                $tocado = true;
                ++$reapuntados;

                $io->text(sprintf(
                    '  %s · %s: «%s» → «%s»',
                    $simula ? 'haría ' : 'hecho ',
                    $slug,
                    $cambio['de'],
                    $cambio['a'],
                ));

                if (!$simula) {
                    $pivote->setComponente($bueno);
                    $pivote->setTarifaPredeterminada(null);
                }
            }

            if (!$tocado) {
                $io->text(sprintf('  ya está  · %s', $slug));
            }
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->section('Textos');
        $escritos = 0;

        foreach (self::TEXTOS as $slug => $texto) {
            $segmento = $this->segmento($slug);

            if ($segmento === null) {
                $io->text(sprintf('  no existe · %s', $slug));
                continue;
            }

            // ⚠️ No basta con que el español coincida: hay que comprobar que **se tradujo**. Si
            // el listener falló —pasó con un segmento al desplegar— el texto queda en un solo
            // idioma y una comparación por contenido lo daría por bueno para siempre. Con esto,
            // volver a correr el comando lo reintenta.
            $traducido = count($segmento->getTitulo()) > 1 && count($segmento->getContenido()) > 1;

            if ($traducido
                && ($segmento->getContenido()[0]['content'] ?? '') === $texto['contenido']
                && ($segmento->getTitulo()[0]['content'] ?? '') === $texto['titulo']) {
                $io->text(sprintf('  ya está  · %s', $slug));
                continue;
            }

            ++$escritos;
            $io->text(sprintf('  %s · %-32s «%s»', $simula ? 'haría ' : 'hecho ', $slug, $texto['titulo']));

            if ($simula) {
                continue;
            }

            // Sin la sobrescritura el listener respeta lo ya traducido y la corrección se queda
            // sólo en español — la trampa de §7 del doc de carga.
            $segmento->setSobreescribirTraduccion(true);
            $segmento->setTitulo([['language' => 'es', 'content' => $texto['titulo']]]);
            $segmento->setContenido([['language' => 'es', 'content' => $texto['contenido']]]);
        }

        if (!$simula) {
            $this->em->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s %d bimodal(es), %d componente(s) reapuntado(s) y %d texto(s).',
            $simula ? 'Se partirían' : 'Partidos',
            $partidos,
            $reapuntados,
            $escritos,
        ));

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    /**
     * Mete el segmento nuevo en cada plantilla donde esté el viejo, y renumera lo que va detrás.
     *
     * @param array{nuevoSlug: string, antes: bool, ...} $def
     */
    private function insertarEnPlantillas(SymfonyStyle $io, TravelSegmento $viejo, array $def, bool $simula): void
    {
        /** @var list<TravelItinerarioSegmentoRel> $rels */
        $rels = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
            ->findBy(['segmento' => $viejo]);

        if ($rels === []) {
            $io->text('  sin plantillas: sólo vive en el pool.');

            return;
        }

        foreach ($rels as $rel) {
            $itinerario = $rel->getItinerario();
            $dia = $rel->getDia();
            $posicion = $def['antes'] ? $rel->getOrden() : $rel->getOrden() + 1;

            $io->text(sprintf(
                '  %s · en «%s» día %d, %s del bus (orden %d)',
                $simula ? 'haría ' : 'hecho ',
                $itinerario?->getNombreInterno() ?? '?',
                $dia,
                $def['antes'] ? 'antes' : 'después',
                $posicion,
            ));

            if ($simula) {
                continue;
            }

            // Hueco: todo lo que va en esa posición o detrás, un paso más allá.
            /** @var list<TravelItinerarioSegmentoRel> $delDia */
            $delDia = $this->em->getRepository(TravelItinerarioSegmentoRel::class)
                ->findBy(['itinerario' => $itinerario, 'dia' => $dia]);

            foreach ($delDia as $otro) {
                if ($otro->getOrden() >= $posicion) {
                    $otro->setOrden($otro->getOrden() + 1);
                }
            }

            $nuevo = $this->segmento($def['nuevoSlug']);

            if ($nuevo !== null && $itinerario !== null) {
                $this->em->persist(
                    (new TravelItinerarioSegmentoRel())
                        ->setItinerario($itinerario)
                        ->setSegmento($nuevo)
                        ->setDia($dia)
                        ->setOrden($posicion),
                );
            }

            $this->em->flush();
        }
    }

    private function segmento(string $slug): ?TravelSegmento
    {
        return $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);
    }
}
