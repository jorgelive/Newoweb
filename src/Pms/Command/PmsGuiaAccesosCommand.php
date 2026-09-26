<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cómo se abre cada puerta y cuántos escalones tiene cada casita.
 *
 * ── De dónde salen estos datos ──────────────────────────────────────────────
 * De Jorge, dictados casita por casita el 20 y el 21/09/2026. No estaban escritos en ninguna
 * parte: el agente escalaba «¿está en primer piso?», «¿tiene ventilación?», «¿la puerta da a la
 * calle?» y una persona tecleaba la respuesta cada vez. Melanie —que viaja con una persona con
 * discapacidad— lleva desde el lunes esperando, y Marilu entra el 8/10 con dos adultos mayores.
 *
 * ── Dos destinos, y la diferencia importa ───────────────────────────────────
 * **Las cerraduras van a la guía del huésped** (`puerta-casa-N`, visibilidad `cliente`): son
 * instrucciones de llegada, del mismo tipo que el croquis y el vídeo, y decisión explícita de
 * Jorge que se vean. Van en el cuerpo **y** en `agenteContenido`, porque ese campo SUSTITUYE:
 * con el texto viejo, el agente contestaría por chat sin el sentido de giro mientras la guía sí
 * lo dice.
 *
 * **Los escalones NO se publican.** Van sólo al `agenteContenido` de `descripcion-casa-N`, que
 * no tiene grupo de serialización: no sale al catálogo ni a la guía, y el modelo lo lee cuando
 * consulta esa casita. Es lo que pidió Jorge con estas palabras: *«que sea en la pregunta pero
 * que no esté público, ya que muchos son constraints que a muchos no importan pero sumados
 * pueden causar un impacto en la apreciación»*. Una lista de gradas leída de corrido es un muro
 * de peros; contestada cuando preguntan, es un servicio.
 *
 * ── Por qué el sentido de giro y no «a la derecha» ──────────────────────────
 * Porque «a la izquierda» depende de por dónde mires la cerradura, y horario no depende de
 * nada. La caja fuerte usa el mismo vocabulario desde el 20/09 por lo mismo.
 *
 * Nace `hidden` porque es de una vez. Idempotente por contenido.
 */
#[AsCommand(
    name: 'app:pms:guia:accesos',
    description: 'Carga cerraduras (guía) y escalones (sólo agente) de las siete casitas. Idempotente.',
    hidden: true,
)]
final class PmsGuiaAccesosCommand extends Command
{
    /**
     * La cerradura de cada puerta, para el huésped.
     *
     * Los tres casos especiales van contados en POSITIVO: lo que está abierto se anuncia antes
     * de que lo descubra —una cerradura sin echar se lee como descuido si nadie la nombra— y el
     * tirón de la 4 va como truco de la casa, no como aviso de que algo falla.
     *
     * @var array<int, string>
     */
    private const array CERRADURAS = [
        1 => '🔑 Tiene una sola cerradura: gira la llave en sentido antihorario para abrir.',

        2 => '🔑 Verás dos cerraduras. La que se usa es la de abajo: gira la llave en sentido '
            . 'antihorario. La de arriba es opcional y te la dejamos abierta, así que a tu '
            . 'llegada sólo necesitas ésa; tienes llave de las dos por si durante tu estancia '
            . 'prefieres echarla también.',

        3 => '🔑 Tiene una sola cerradura: gira la llave en sentido horario para abrir.',

        4 => '🔑 Tiene una sola cerradura, en sentido horario. Un truco: tira suavemente de la '
            . 'puerta hacia ti mientras giras la llave y abrirá sin forzar.',

        5 => '🔑 Tiene una sola cerradura: gira la llave en sentido antihorario para abrir.',

        6 => '🔑 Al llegar encontrarás dos puertas. Sólo la exterior está cerrada: gira la llave '
            . 'en sentido horario. La interior te la dejamos sin asegurar para que entres '
            . 'directo — si durante tu estancia prefieres cerrarla, las llaves que tienes '
            . 'también sirven.',

        7 => '🔑 Tiene una sola cerradura: gira la llave en sentido horario para abrir.',
    ];

    /**
     * Los escalones y los niveles, sólo para el agente.
     *
     * Cada uno lleva su VERBO —«se suben», «se bajan»—, que es lo que pidió Jorge: un número
     * suelto obliga a suponer la dirección, y la dirección es justo lo que pregunta quien
     * pregunta por escalones.
     *
     * Y separa siempre **acceso** (del pasaje a la puerta) de **interior** (la escalera de
     * dentro). Al dictarlos se contaron juntos una vez y el texto decía que la 6 subía 20 «y
     * dentro es dúplex», callando los 12 peldaños de arriba. El total va escrito donde existe,
     * porque 20 y 12 por separado suenan a dos cosas pequeñas y juntos son tres pisos.
     *
     * @var array<int, string>
     */
    private const array NIVELES = [
        1 => 'La puerta está en la calle, sin gradas de pasaje: es la más conveniente con '
            . 'movilidad reducida. Dentro se BAJAN 2 escalones de la calle a la sala y 2 más de '
            . 'la sala a las habitaciones. Quien vaya en silla de ruedas tiene que saber esos '
            . 'cuatro.',

        2 => 'La puerta está en la calle, pero la vivienda NO: se SUBEN 10 escalones hasta un '
            . 'segundo nivel donde está todo. Dentro se suben 2 más a la sala y 1 pequeño a la '
            . 'cocina. ⚠️ No digas «es a nivel de calle» a secas: es cierto para encontrarla y '
            . 'falso para vivirla.',

        3 => 'En el pasaje, al pie de las gradas: no se sube nada para entrar. Dentro se BAJAN 3 '
            . 'escalones al comedor con cocina y 2 más a las habitaciones.',

        4 => 'En el pasaje, sin gradas para entrar. Dentro se BAJAN 3 escalones al distribuidor '
            . '—donde están la cocina pequeña, el baño y las habitaciones— y 2 más a las '
            . 'habitaciones.',

        5 => 'Se SUBEN 10 escalones desde el pasaje, un nivel. Dentro no se sube ni se baja nada: '
            . 'todo en la misma planta. Es la opción para quien puede subir una vez pero no '
            . 'quiere escaleras durante la estancia.',

        6 => 'Para llegar a la puerta se SUBEN 20 escalones (dos tramos de 10). Dentro es dúplex: '
            . 'áreas comunes abajo y habitaciones arriba, con una escalera interior de 12 '
            . 'peldaños, y hay un baño privado en cada nivel. Quien duerma arriba sube 32 desde '
            . 'el pasaje. A cambio, los dormitorios quedan aparte de la zona común.',

        7 => 'Para llegar a la puerta se SUBEN 20 escalones (dos tramos de 10). Las áreas comunes '
            . 'y los dos baños privados están en el primer nivel; a la habitación del segundo se '
            . 'suben 10 peldaños más. Quien duerma en el primer nivel no vuelve a subir nada en '
            . 'toda la estancia.',
    ];

    /** La cabecera del bloque del agente. Es la instrucción que evita el muro de peros. */
    private const string CABECERA_NIVELES = 'ACCESO Y NIVELES. No lo cuentes de entrada: es para '
        . 'cuando pregunten por gradas, escaleras, accesibilidad o si viaja alguien mayor o con '
        . 'movilidad reducida. Contesta sólo por ESTA casita salvo que pidan comparar, y cuando '
        . 'nombres una gradería nombra también lo que compensa.';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $tocados = 0;

        $io->section('Cerraduras (guía del huésped)');

        foreach (self::CERRADURAS as $casa => $texto) {
            $tocados += $this->cerradura($io, $casa, $texto, $simular) ? 1 : 0;
        }

        $io->section('Accesos y niveles (sólo agente)');

        foreach (self::NIVELES as $casa => $texto) {
            $tocados += $this->niveles($io, $casa, $texto, $simular) ? 1 : 0;
        }

        if (!$simular && $tocados > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('%d ficha(s) %s.', $tocados, $simular ? 'cambiarían' : 'al día'));

        if (!$simular && $tocados > 0) {
            $io->note('Los otros seis idiomas del cuerpo los rehace AutoTranslate al guardar.');
        }

        return Command::SUCCESS;
    }

    /** El párrafo de la cerradura, en el cuerpo del huésped y en el texto del agente. */
    private function cerradura(SymfonyStyle $io, int $casa, string $texto, bool $simular): bool
    {
        $item = $this->ficha('puerta-casa-' . $casa);

        if ($item === null) {
            $io->warning(sprintf('No existe «puerta-casa-%d».', $casa));

            return false;
        }

        $enHtml = '<p>' . $texto . '</p>';
        $cuerpo = $this->espanol($item->getDescripcion());
        $agente = (string) $item->getAgenteContenido();

        if (str_contains($cuerpo, $texto) && str_contains($agente, $texto)) {
            $io->text(sprintf('· Casa %d ya la tiene.', $casa));

            return false;
        }

        $io->text(sprintf('<info>~</info> Casa %d · %s', $casa, mb_substr(strip_tags($texto), 0, 70) . '…'));

        if ($simular) {
            return true;
        }

        // El párrafo va DELANTE del croquis: primero se abre la puerta que ya encontraste, y el
        // croquis sirve para encontrarla. Si fuera al final quedaría después del vídeo.
        if (!str_contains($cuerpo, $texto)) {
            $this->escribirEspanol($item, $cuerpo === '' ? $enHtml : $enHtml . "\n" . $cuerpo);
        }

        if (!str_contains($agente, $texto)) {
            $item->setAgenteContenido(trim($texto . "\n\n" . $agente));
        }

        return true;
    }

    /** El bloque de escalones, sólo en el texto del agente de la descripción. */
    private function niveles(SymfonyStyle $io, int $casa, string $texto, bool $simular): bool
    {
        $item = $this->ficha('descripcion-casa-' . $casa);

        if ($item === null) {
            $io->warning(sprintf('No existe «descripcion-casa-%d».', $casa));

            return false;
        }

        $agente = (string) $item->getAgenteContenido();

        if (str_contains($agente, $texto)) {
            $io->text(sprintf('· Casa %d ya lo tiene.', $casa));

            return false;
        }

        $io->text(sprintf('<info>~</info> Casa %d · %s', $casa, mb_substr($texto, 0, 70) . '…'));

        if ($simular) {
            return true;
        }

        // ⚠️ Se AÑADE al final: este campo sustituye al cuerpo publicado, así que reemplazarlo
        // dejaría al agente sin la distribución, la capacidad ni el equipamiento.
        $item->setAgenteContenido(trim($agente . "\n\n" . self::CABECERA_NIVELES . "\n\n" . $texto));

        return true;
    }

    private function ficha(string $codigo): ?PmsGuiaItem
    {
        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => $codigo]);

        return $item instanceof PmsGuiaItem ? $item : null;
    }

    /** @param list<array<string, mixed>>|null $i18n */
    private function espanol(?array $i18n): string
    {
        foreach ($i18n ?? [] as $fila) {
            if (($fila['language'] ?? null) === 'es') {
                return is_string($fila['content'] ?? null) ? $fila['content'] : '';
            }
        }

        return '';
    }

    /**
     * Reescribe SÓLO el español y deja que el listener rehaga el resto.
     *
     * Se manda una lista con un único idioma a propósito: `AutoTranslate` rellena los vacíos, y
     * conservar las traducciones viejas dejaría seis idiomas sin el párrafo de la cerradura.
     */
    private function escribirEspanol(PmsGuiaItem $item, string $contenido): void
    {
        $item->setDescripcion([['language' => 'es', 'content' => $contenido]]);
    }
}
