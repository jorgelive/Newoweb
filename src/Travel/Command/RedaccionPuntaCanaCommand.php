<?php

declare(strict_types=1);

namespace App\Travel\Command;

use App\Travel\Entity\TravelSegmento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Unifica la redacción de los segmentos que usa el expediente de Punta Cana.
 *
 * Arregla tres cosas distintas, y conviene no confundirlas:
 *
 * **1. El marcado, que NO es cuestión de gusto.** `TravelSegmento::$contenido` está declarado
 * `#[AutoTranslate(format: 'html')]` y `pax` lo pinta con `v-html` dentro de un contenedor
 * Tailwind `prose`, que estiliza **por selector de elemento**. Un texto sin `<p>` no recibe
 * `leading-relaxed` ni margen de párrafo: se ve con otro interlineado y pegado al de al lado, en
 * la misma lista. Medido en el catálogo: 137 segmentos con `<p>` y 47 pelados, mezclados.
 *
 * **2. La entradilla en negrita con emoji** —«✈️ **De Lima a Punta Cana:**»— la usan **35 de 184**
 * segmentos. Es la minoría, y dentro de este expediente sólo la tienen los vuelos y los traslados
 * de aeropuerto: el cliente lee «Navegación hasta Isla Saona» y dos líneas después «✈️ **De Lima a
 * Punta Cana:** Volamos desde la bruma del Pacífico». Cambia la voz, la persona y el formato.
 *
 * ⚠️ **El grupo mayoritario (102) tampoco es el modelo a seguir**, y por eso no se unifica hacia
 * él: es contenido antiguo con prosa de folleto («Prepárate para desafiar las alturas»), mezcla de
 * tú y nosotros, y restos de editor (`data-path-to-node="1"`) pegados dentro del HTML. Lo que se
 * conserva de ahí es sólo el `<p>`.
 *
 * **3. Los títulos de los traslados de Punta Cana**, que son los cuatro más largos del catálogo
 * (67-73 caracteres) mientras el resto del expediente dice «Del aeropuerto a Miraflores» (27) o
 * «Llegada y check-in» (18). Empezaban por la categoría («Transporte») en vez de por prosa y
 * repetían «Punta Cana» dos veces en la misma línea, cuando la ciudad ya la establece el día del
 * itinerario.
 *
 * ⚠️ **Sólo cambia el título del CLIENTE.** El `nombreInterno` se queda largo y con la ciudad: ahí
 * sí hace falta desambiguar el Coco Bongo de Punta Cana del de otras ciudades, y es lo que ve el
 * operador en los desplegables.
 *
 * ⚠️ **Esto NO toca las cotizaciones ya armadas**: `cotizacion_segmento` congela título y
 * contenido al insertar. Para bajar el texto nuevo a un expediente abierto está
 * `app:cotizacion:refrescar-textos-maestros`.
 */
#[AsCommand(
    name: 'app:travel:redaccion-punta-cana',
    description: 'Unifica marcado, voz y títulos de los segmentos del expediente de Punta Cana.',
)]
final class RedaccionPuntaCanaCommand extends Command
{
    /**
     * Títulos de cliente: cortos, en el registro del resto del expediente.
     *
     * @var array<string, string>
     */
    private const TITULOS = [
        'TRANS-APT_PUJ-HOTEL_PUJ' => 'Del aeropuerto al resort',
        'TRANS-HTL_PUJ-AEROPUERTO_PUJ' => 'Regreso al aeropuerto de Punta Cana',
        'TRANS-HTL_PUJ-COCO_BONGO_PUJ' => 'Del resort a Coco Bongo',
        'TRANS-COCO_BONGO_PUJ-HTL_PUJ' => 'Regreso de Coco Bongo al resort',
    ];

    /**
     * Textos reescritos. Van ya con `<p>`, en tercera persona y sin entradilla.
     *
     * @var array<string, string>
     */
    private const TEXTOS = [
        // Le faltaba lo único que el cliente quiere saber: qué cocinas hay. Y abría con el
        // trámite de la reserva —la mitad del texto—, que es lo que limita. Lo que limita va al
        // final: la misma regla que rige la guía del huésped.
        'CEN-RESORT-TEMATICO' => '<p>Cena a la carta en uno de los restaurantes temáticos del resort:'
            .' italiano, japonés, hindú o mexicano, según el hotel. Las plazas se reservan en'
            .' recepción al llegar.</p>',

        // Estaban vacíos: los segmentos se crearon con título y sin cuerpo, así que en la guía
        // del cliente salían mudos.
        'TRANS-HTL_PUJ-COCO_BONGO_PUJ' => '<p>Traslado del resort a Coco Bongo al comenzar la noche.'
            .' El trayecto por la zona de Bávaro ronda los veinte minutos, según dónde esté el'
            .' alojamiento.</p>',
        'TRANS-COCO_BONGO_PUJ-HTL_PUJ' => '<p>Traslado de regreso al resort al terminar el'
            .' espectáculo, ya de madrugada. El punto y la hora de encuentro se confirman a la'
            .' salida.</p>',

        // Los cuatro con entradilla de emoji y negrita, pasados a la voz del resto del expediente.
        // Se conserva lo que decían; se quita el «Volamos»/«nos estará esperando» y el rótulo.
        'VUELO-CUZ-LIM' => '<p>Vuelo de Cusco a Lima, descendiendo desde los 3 400 metros hasta la'
            .' costa. Lima aparece al final del trayecto, tendida sobre el desierto del'
            .' Pacífico.</p>',
        'VUELO-LIM-PUJ' => '<p>Vuelo de Lima a Punta Cana, de la bruma del Pacífico al extremo'
            .' oriental del Caribe dominicano. La línea de playa aparece justo antes del'
            .' descenso.</p>',
        'TRANS-APT_PUJ-HOTEL_PUJ' => '<p>Traslado del aeropuerto de Punta Cana al resort, con'
            .' recepción a la salida de migraciones. El trayecto bordea la costa y ronda la media'
            .' hora, según dónde esté el alojamiento.</p>',
        'TRANS-HTL_PUJ-AEROPUERTO_PUJ' => '<p>Traslado del resort al aeropuerto de Punta Cana, con'
            .' recojo a la hora que pida la aerolínea.</p>',
    ];

    /**
     * Los que sólo necesitan el `<p>`: el texto está bien, le falta el marcado.
     *
     * @var list<string>
     */
    private const ENVOLVER = [
        'ACT-RESORT-CHECKIN_AM',
        'ACT-RESORT-CHECKIN_PM',
        'ACT-RESORT-PISCINA_PLAYA',
        'ACT-RESORT-RECREATIVAS',
        'ACT-RESORT-SHOW',
        'ALM-RESORT-BUFFET',
        'ALM-WALK_MIR-LARCOMAR',
        'CEN-RESORT-BUFFET',
        'DES-APT_LIM',
        'DES-RESORT-BUFFET',
        'RET_EXC-WALK_MIR-AEROPUERTO',
        'SAL_EXC-WALK_MIR',
        'VIS-COCO_BONGO-FIESTA_BLANCA',
        'VIS-COCO_BONGO-GENERAL',
        'VIS-SAONA-CLASICO',
        'VIS-SAONA-VIP',
        'VIS-WALK_MIR-KENNEDY',
        'VIS-WALK_MIR-LARCOMAR_LIBRE',
        'VIS-WALK_MIR-MALECON',
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

        $io->section('Títulos de cliente');
        $titulos = $this->titulos($io, $simula);

        $io->section('Textos reescritos');
        $textos = $this->textos($io, $simula);

        $io->section('Textos a los que sólo les falta el <p>');
        $envueltos = $this->envolver($io, $simula);

        if (!$simula) {
            $this->em->flush();
        }

        $io->section('Resumen');
        $io->text(sprintf('  títulos: %d · textos reescritos: %d · envueltos en <p>: %d', $titulos, $textos, $envueltos));

        $io->note('Se escribe SÓLO el español; el listener regenera los otros seis idiomas.');
        $io->note('Los expedientes ya armados NO cambian: usa app:cotizacion:refrescar-textos-maestros.');

        if ($simula) {
            $io->note('Ensayo: no se escribió nada. Quita --dry-run para aplicarlo.');
        }

        return Command::SUCCESS;
    }

    private function titulos(SymfonyStyle $io, bool $simula): int
    {
        $tocados = 0;

        foreach (self::TITULOS as $slug => $titulo) {
            $segmento = $this->buscar($slug, $io);

            if ($segmento === null) {
                continue;
            }

            $actual = $this->español($segmento->getTitulo());

            if ($actual === $titulo) {
                $io->text(sprintf('  ya está · %s', $slug));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'cambiaría' : 'cambiado ', $slug));
            $io->text(sprintf('      %d car. · %s', mb_strlen($actual), $actual));
            $io->text(sprintf('    → %d car. · %s', mb_strlen($titulo), $titulo));
            ++$tocados;

            if (!$simula) {
                $segmento->setTitulo([['language' => 'es', 'content' => $titulo]]);
            }
        }

        return $tocados;
    }

    private function textos(SymfonyStyle $io, bool $simula): int
    {
        $tocados = 0;

        foreach (self::TEXTOS as $slug => $texto) {
            $segmento = $this->buscar($slug, $io);

            if ($segmento === null) {
                continue;
            }

            $actual = $this->español($segmento->getContenido());

            if ($actual === $texto) {
                $io->text(sprintf('  ya está · %s', $slug));
                continue;
            }

            $io->text(sprintf('  %s · %s', $simula ? 'cambiaría' : 'cambiado ', $slug));
            $io->text(sprintf('      %s', $actual === '' ? '(vacío)' : $this->recorte($actual)));
            $io->text(sprintf('    → %s', $this->recorte($texto)));
            ++$tocados;

            if (!$simula) {
                $segmento->setContenido([['language' => 'es', 'content' => $texto]]);
            }
        }

        return $tocados;
    }

    private function envolver(SymfonyStyle $io, bool $simula): int
    {
        $tocados = 0;

        foreach (self::ENVOLVER as $slug) {
            $segmento = $this->buscar($slug, $io);

            if ($segmento === null) {
                continue;
            }

            $actual = $this->español($segmento->getContenido());

            if ($actual === '' || str_contains($actual, '<p>')) {
                $io->text(sprintf('  ya está · %s', $slug));
                continue;
            }

            $envuelto = $this->enParrafos($actual);

            $io->text(sprintf('  %s · %s', $simula ? 'envolvería' : 'envuelto  ', $slug));
            ++$tocados;

            if (!$simula) {
                $segmento->setContenido([['language' => 'es', 'content' => $envuelto]]);
            }
        }

        return $tocados;
    }

    /**
     * Envuelve en `<p>` respetando los párrafos que ya hubiera.
     *
     * ⚠️ Se parte por líneas en blanco, no se envuelve el bloque entero: si el texto tenía dos
     * párrafos separados por un salto, meterlos en un solo `<p>` los fundiría en uno y el cambio
     * de formato pasaría por corrección tipográfica.
     */
    private function enParrafos(string $texto): string
    {
        $partes = preg_split('/\R{2,}/u', trim($texto)) ?: [$texto];
        $parrafos = [];

        foreach ($partes as $parte) {
            $limpio = trim(preg_replace('/\s+/u', ' ', $parte) ?? $parte);

            if ($limpio !== '') {
                $parrafos[] = sprintf('<p>%s</p>', $limpio);
            }
        }

        return implode("\n", $parrafos);
    }

    private function buscar(string $slug, SymfonyStyle $io): ?TravelSegmento
    {
        $segmento = $this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $slug]);

        if ($segmento === null) {
            $io->text(sprintf('  no existe · %s', $slug));
        }

        return $segmento;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $i18n
     */
    private function español(array $i18n): string
    {
        foreach ($i18n as $bloque) {
            if (($bloque['language'] ?? '') === 'es') {
                return (string) ($bloque['content'] ?? '');
            }
        }

        return '';
    }

    private function recorte(string $texto): string
    {
        return mb_strlen($texto) > 88 ? mb_substr($texto, 0, 88).'…' : $texto;
    }
}
