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
 * Quién recibe las maletas, que la ficha no lo decía.
 *
 * ── Por qué hace falta justo ahora ──────────────────────────────────────────
 * Desde el 18/09/2026 el agente sabe —y dice— que NO hay recepción ni personal en el sitio. Eso
 * era lo que faltaba, pero deja a esta ficha coja: promete un almacén para el equipaje y no
 * cuenta quién lo recibe, así que a «¿y quién me guarda las maletas?» al modelo sólo le quedan
 * dos salidas malas, inventarse a alguien o negar un servicio que sí existe. Es la regla de la
 * casa: **lo que se le quita a un texto, el modelo lo niega**.
 *
 * ── Cómo funciona de verdad ─────────────────────────────────────────────────
 * Lo que decide todo es **la hora**, y por eso el texto gira alrededor de preguntarla:
 *
 * | Momento | Dónde queda | Quién abre |
 * |---|---|---|
 * | Recojo dentro del horario de limpieza | donde esté | el personal se lo entrega |
 * | Recojo más tarde | apartamento o almacén, según la hora | se coordina por el chat |
 * | Llegada con la limpieza ya empezada | el propio apartamento | el personal lo recibe |
 * | Llegada demasiado temprano | el almacén | el propio huésped, con su clave |
 *
 * El almacén no tiene personal —lo abre el propio huésped con una clave—, así que hay casos en
 * que saca su equipaje **solo**: por eso no se puede escribir ni «siempre te lo entregan» ni
 * «cógelo cuando quieras». Lo único cierto siempre es que hay que decir la hora. Y en la llegada
 * anticipada la hora se pide **con antelación**, no al plantarse en la puerta: es lo que da
 * margen para tenerlo resuelto.
 *
 * ⚠️ **«Apartamento», nunca «departamento».** La primera pasada usó la palabra peruana y la
 * traducción la entendió como el departamento de una empresa: «ils resteront au service» en
 * francés, «it will stay in the department» en inglés. Es el tercer aviso del mismo día —«la
 * caja de arriba» salió como el piso de arriba, y «bultos» no se entendía fuera de Perú—: lo
 * que aquí se escribe se lee en siete idiomas, y el traductor no conoce el sitio.
 *
 * ── El reparto entre los dos textos ─────────────────────────────────────────
 * Al HUÉSPED le toca su parte —cuántas piezas, a qué hora vuelve, la foto— y saber que, según
 * esa hora, se lo entregan o lo abre él. La tabla de arriba entera va en el texto del AGENTE,
 * que es quien tiene que elegir la rama sin inventársela. Y el acceso al almacén NO lo da el
 * agente: se pasa al coordinar, como el de la caja del dinero.
 *
 * ── Por qué comando y no SQL ────────────────────────────────────────────────
 * `descripcion` lleva `#[AutoTranslate]`: un `UPDATE` se salta el listener y los otros seis
 * idiomas se quedarían sin el párrafo. Se toca sólo el español y el listener rehace el resto.
 * `agenteContenido` no se traduce —lo lee el modelo, que ya traduce—, pero se cambia aquí mismo
 * para que las dos versiones no se separen.
 *
 * Nace `hidden` porque es de una vez. Idempotente: si el párrafo ya está, no toca nada.
 */
#[AsCommand(
    name: 'app:pms:guia:equipaje-quien-recibe',
    description: 'Añade a «Equipaje y horarios flexibles» quién recibe las maletas. Idempotente.',
    hidden: true,
)]
final class PmsGuiaEquipajeQuienRecibeCommand extends Command
{
    private const string FICHA = 'Equipaje y horarios flexibles (general)';

    /**
     * El párrafo que hoy cierra la ficha: se sustituye entero.
     *
     * ⚠️ Y de paso se va «bultos», que era la palabra equivocada para un texto que se traduce a
     * siete idiomas: en castellano de Perú se entiende, pero fuera es un fardo o un chichón, y
     * el traductor no tiene forma de saber que hablamos de maletas. «Piezas de equipaje» viaja.
     */
    private const string VIEJO = '<p>🧳 Cuando lo dejes, dinos <strong>cuántos bultos</strong> '
        . 'son. Puedes recogerlo antes de lo previsto sin problema.</p>';

    /**
     * Lo que se le pide al huésped y lo que se le promete, sacado de lo que pregunta de verdad.
     *
     * De los 78 mensajes entrantes que hablan de equipaje, **31 traen una hora**: casi nadie
     * pregunta si el servicio existe, preguntan cuándo y cómo. Y la primera respuesta del equipo,
     * siempre, es «solo me indicas hasta qué hora lo guardamos». Por eso la hora se pide aquí y
     * no se deja para una repregunta.
     *
     * ⚠️ Ni «te lo entregan» ni «cógelo tú»: son las dos ramas, y la elige la hora. Prometer una
     * sola deja la mitad de los casos contradichos — el 15/06 una huésped se llevó un juego de
     * llaves «para asegurarnos de poder sacar el equipaje a las 2:30» y tuvo que volver a
     * subirlas. Y el borrador anterior decía «no tienes que esperar a nadie», que para el RECOJO
     * no es verdad si cae fuera del horario de limpieza.
     */
    private const string NUEVO = '<p>🧳 Al dejarlo, dinos <strong>cuántas piezas de equipaje</strong> '
        . 'son y <strong>a qué hora lo recoges</strong>, y mándanos una <strong>foto por '
        . 'WhatsApp</strong>: así queda registrado. Si vuelves mientras el personal de limpieza '
        . 'está trabajando, ellas te lo entregan; si es más tarde, lo coordinamos por el chat y te '
        . 'decimos dónde está — según la hora se queda en el propio apartamento o va al almacén, '
        . 'que abres tú mismo con la clave que te damos, sin depender de nadie.'
        . "</p>\n"
        . '<p>🕘 Y si <strong>llegas antes de la hora de entrada</strong>, dinos <strong>con '
        . 'antelación</strong> a qué hora llegas, en cuanto lo sepas, y escríbenos también al '
        . 'llegar: si la limpieza ya empezó, el personal te recibe el equipaje en el apartamento; '
        . 'si aún es muy temprano, lo dejas tú en el almacén y te pasamos la clave.</p>';

    /** Lo que ya está en `agenteContenido` y se reescribe: también decía «bultos». */
    private const string ANCLA_AGENTE = 'día de antelación para coordinarlo, y que diga cuantos bultos son.';

    /**
     * El precio, que estaba mandando a preguntar algo que se sabe.
     *
     * Decía «Si pregunta el costo, avisa al equipo» y el guardaequipaje **es gratis** — Jorge se
     * lo escribió a Vanessa el 30/08 y me lo confirmó el 25/09. Con esa línea, cada «¿cuánto
     * cuesta?» interrumpía a una persona para decir cero. El agente llegó a contestar «es
     * gratuito» por su cuenta el 04/09 y acertó, que es la misma suerte que tuvo con la
     * recepción y con el dúplex.
     *
     * ⚠️ **Y no entra en la guía del huésped**, por decisión de Jorge: gratis *si preguntan*.
     * Anunciarlo en la ficha lo convierte en un servicio ofrecido, con lo que eso arrastra —el
     * que deja maletas tres semanas «porque pone que es gratis»—; contestado cuando preguntan,
     * es una buena noticia. Mismo criterio que el pasaje de las motos.
     */
    private const string COSTO_VIEJO = 'Si pregunta el costo,\navisa al equipo.';

    private const string COSTO = 'Si pregunta el costo: es GRATIS para nuestros huéspedes, díselo '
        . 'sin rodeos y sin avisar a nadie. No lo ofrezcas de entrada — se cuenta cuando lo '
        . 'preguntan, no antes.';

    private const string AGENTE = 'día de antelación para coordinarlo.'
        . "\n\n"
        . "LO PRIMERO ES LA HORA. Aquí no hay una respuesta única: dónde queda el equipaje y "
        . "quién lo abre dependen de a qué hora lo deja y a qué hora vuelve. Pregúntaselo "
        . "SIEMPRE, junto con cuántas PIEZAS de equipaje son, y pídele una foto por WhatsApp: "
        . "esa foto es el registro y con ella no tiene que esperar a nadie para dejarlo."
        . "\n\n"
        . "AL RECOGER:\n"
        . "- Si vuelve mientras el personal de limpieza está trabajando, ellas se lo entregan.\n"
        . "- Si vuelve más tarde, se coordina por el chat. Dile que lo coordinamos y avisa al "
        . "equipo; no le cites tú a ninguna hora ni le prometas que habrá alguien.\n"
        . "- Según la hora se queda en el propio apartamento o va al almacén. En el almacén no "
        . "hay personal: lo abre el propio huésped con una clave, y esa clave se la pasa el "
        . "equipo al coordinar. NO la des tú ni la busques por otro lado."
        . "\n\n"
        . "AL LLEGAR ANTES DEL CHECK-IN:\n"
        . "- Si la limpieza ya empezó, el personal le recibe el equipaje en el apartamento.\n"
        . "- Si todavía es muy temprano y no ha empezado, lo deja él mismo en el almacén.\n"
        . "PÍDELE LA HORA CON ANTELACIÓN, en cuanto la sepa, y no sólo cuando ya esté aquí: "
        . "saberlo antes es lo que permite tenerlo resuelto. Que escriba también al llegar, y "
        . "no le digas que se plante en la puerta a esperar — ya pasó y fueron cuatro horas.";

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

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => self::FICHA]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::FICHA));

            return Command::FAILURE;
        }

        $tocado = false;

        // ── El texto del huésped ────────────────────────────────────────────
        $descripcion = $item->getDescripcion();
        $indice = null;

        foreach ($descripcion as $i => $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $indice = $i;
                break;
            }
        }

        if ($indice === null) {
            $io->error('La ficha no tiene descripción en español.');

            return Command::FAILURE;
        }

        $texto = (string) ($descripcion[$indice]['content'] ?? '');

        // La marca de «ya está hecho» es una frase de `NUEVO`, y hay que moverla cada vez que se
        // pula la redacción: ya pasó dos veces en una tarde, y con el centinela viejo el comando
        // habría vuelto a entrar sobre un texto ya cambiado.
        if (str_contains($texto, 'el propio apartamento o va al almacén')) {
            $io->text('· La guía ya lo cuenta.');
        } elseif (str_contains($texto, 'el propio departamento o va al almacén')) {
            // La pasada anterior ya escribió estos párrafos con «departamento». No se vuelve al
            // texto original para repetir el reemplazo: se corrige en sitio la palabra, que es
            // lo único que cambia.
            $io->section('Guía (español)');
            $io->writeln('<fg=green>~ departamento → apartamento (en francés salía «le service»)</>');

            if (!$simular) {
                $descripcion[$indice]['content'] = str_replace(
                    ['el propio departamento o va al almacén', 'el equipaje en el departamento'],
                    ['el propio apartamento o va al almacén', 'el equipaje en el apartamento'],
                    $texto
                );
                $item->setDescripcion($descripcion);
            }

            $tocado = true;
        } elseif (!str_contains($texto, self::VIEJO)) {
            // A ciegas no: si el párrafo de los bultos no está tal cual, alguien lo editó y
            // pegar aquí el nuevo dejaría la ficha diciendo dos veces lo mismo de otra manera.
            $io->error('No encuentro el párrafo de los bultos tal cual: míralo en el panel.');

            return Command::FAILURE;
        } else {
            $io->section('Guía (español)');
            $io->writeln('<fg=green>+ piezas y hora de recojo · foto por WhatsApp · las dos ramas · llegada anticipada</>');

            if (!$simular) {
                $descripcion[$indice]['content'] = str_replace(self::VIEJO, self::NUEVO, $texto);
                $item->setDescripcion($descripcion);
            }

            $tocado = true;
        }

        // ── El precio, que va SÓLO aquí ─────────────────────────────────────
        $agente = (string) $item->getAgenteContenido();

        if (str_contains($agente, self::COSTO_VIEJO)) {
            $io->section('Agente · precio');
            $io->writeln('<fg=red>- Si pregunta el costo, avisa al equipo.</>');
            $io->writeln('<fg=green>+ es GRATIS, díselo sin avisar a nadie; no lo ofrezcas de entrada</>');

            if (!$simular) {
                $item->setAgenteContenido(str_replace(self::COSTO_VIEJO, self::COSTO, $agente));
                $agente = (string) $item->getAgenteContenido();
            }

            $tocado = true;
        }

        // ── Lo que lee el agente ────────────────────────────────────────────

        if (str_contains($agente, 'propio apartamento o va al almacén')) {
            $io->text('· El texto del agente ya lo cuenta.');
        } elseif (str_contains($agente, 'propio departamento o va al almacén')) {
            $io->section('Agente');
            $io->writeln('<fg=green>~ departamento → apartamento</>');

            if (!$simular) {
                $item->setAgenteContenido(str_replace(
                    ['propio departamento o va al almacén', 'el equipaje en el departamento'],
                    ['propio apartamento o va al almacén', 'el equipaje en el apartamento'],
                    $agente
                ));
            }

            $tocado = true;
        } elseif (!str_contains($agente, self::ANCLA_AGENTE)) {
            $io->error('El texto del agente no es el que esperaba: míralo en el panel.');

            return Command::FAILURE;
        } else {
            $io->section('Agente');
            $io->writeln('<fg=green>+ la hora manda · recojo y llegada, rama por rama · el almacén no lo abre el agente</>');

            if (!$simular) {
                $item->setAgenteContenido(str_replace(self::ANCLA_AGENTE, self::AGENTE, $agente));
            }

            $tocado = true;
        }

        if (!$tocado) {
            $io->success('Ya estaba todo dicho.');

            return Command::SUCCESS;
        }

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }
}
