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
 * El calefactor: la justificación del precio deja de leerla todo el mundo.
 *
 * ── Qué se mueve ────────────────────────────────────────────────────────────
 * La ficha traía un párrafo de «motivo del costo» —el precio del kWh en Cusco, el consumo de un
 * calefactor de 2000 W en doce horas, y que la mayoría de alojamientos de este rango ni siquiera
 * los ofrece— y **lo leía cualquiera que abriera la ficha, hubiera preguntado o no**. Es
 * defenderse antes de que nadie reclame: quien sólo quería saber el precio se lleva la sensación
 * de que hay algo que justificar.
 *
 * Pasa al texto del agente, que es quien lo necesita el día que alguien dice «¿20 soles por una
 * estufa?». Mismo criterio que con el pasaje de las motos y con que el guardaequipaje es gratis:
 * el dato existe, está escrito, y sale cuando alguien lo busca.
 *
 * ── Y se añade lo que faltaba ───────────────────────────────────────────────
 * «¿Hará falta?» es la repregunta que viene siempre después del precio —«¿tienen calefacción?»,
 * «20 soles», «¿pero los cuartos son fríos?», «¿se va a necesitar de todas maneras?»— y el
 * agente la esquivaba porque no tenía nada escrito, así que el huésped insistía.
 *
 * ⚠️ El orden es **frazadas primero, calefactor después**: lo gratis antes que lo de pago. Al
 * revés suena a que vendemos algo que debería estar incluido, que es exactamente lo que el
 * párrafo del kWh intentaba justificar.
 *
 * Nace `hidden` porque es de una vez. Idempotente por contenido.
 */
#[AsCommand(
    name: 'app:pms:guia:calefactor',
    description: 'Mueve el motivo del costo al texto del agente y añade «¿hará falta?». Idempotente.',
    hidden: true,
)]
final class PmsGuiaCalefactorCommand extends Command
{
    private const string CODIGO = 'calefactor';

    /** Lo que se quita de la guía: el párrafo entero, tal como está escrito. */
    private const string MOTIVO_EN_GUIA = '<p>📌 <strong>Motivo del costo:</strong> <br>En Cusco '
        . 'la electricidad es costosa, con un precio aproximado de <strong>S/ 0.93 por kWh</strong>. '
        . '<br>Un calefactor de <strong>2000 W</strong> utilizado durante 12 horas consume '
        . '<strong>24 kWh</strong>, lo que equivale a un gasto aproximado de '
        . '<strong>S/ 22.32</strong>. <br><br>Adicionalmente, es importante mencionar que '
        . '<strong>la mayoría de alojamientos dentro de este mismo rango de precios no ofrece '
        . 'calefactores ni pone a disposición este tipo de servicio</strong>. Nosotros lo '
        . 'proporcionamos como una opción extra para garantizar mayor comodidad durante su '
        . 'estancia.</p>';

    /**
     * Las frazadas, en la ficha del CALEFACTOR y no en la de toallas.
     *
     * ── Por qué aquí ────────────────────────────────────────────────────────────
     * Hasta hoy el huésped no podía enterarse de que existen: la única mención viva estaba en el
     * conocimiento, que no se publica. Y hay que pedirlas con antelación, así que quien no sabe
     * que existen descubre que las necesita a las once de la noche, cuando ya no se pueden
     * llevar.
     *
     * Pero anunciarlas en «Toallas y ropa de cama» las lee TODO EL MUNDO y se convierten en un
     * extra gratis que se pide por si acaso — y **no hay frazadas para tantas camas**, un par
     * por casita. Aquí las ve quien ya está pensando en el frío, que es quien las necesita: el
     * que duerme bien no abre esta ficha.
     *
     * ⚠️ **Sin cantidad, por decisión de Jorge**, y «algunas» no es un adorno: es lo que hace
     * que quien no pasa frío no las pida. «Tenemos frazadas adicionales sin costo» y «tenemos
     * algunas, si crees que vas a necesitarlas» piden cosas distintas.
     */
    private const string FRAZADAS_EN_GUIA = '<p>🧣 <strong>¿Y si solo tienes algo de frío?</strong> '
        . 'Tenemos <strong>algunas frazadas adicionales sin costo</strong>. Si crees que vas a '
        . 'necesitarlas, pídelas por el chat <strong>con antelación</strong> —no podemos llevarlas '
        . 'en el momento— y te las dejamos preparadas.</p>';

    /** Lo que se añade al agente: la justificación y la respuesta a «¿hará falta?». */
    private const string AGENTE = <<<'TXT'
        SI LE PARECE CARO. No te disculpes ni lo repitas: da el motivo una vez. En Cusco el kWh está a unos S/ 0.93 y un calefactor de 2000 W en las 12 horas del turno consume unos 24 kWh, o sea S/ 22 de electricidad — el alquiler no cubre mucho más que eso. Y la mayoría de alojamientos de este precio ni los ofrece.

        SI PREGUNTA SI HARÁ FALTA. Es la pregunta que viene siempre después del precio, y esquivarla hace que insista. Contesta con la época: de mayo a agosto las noches bajan mucho y el calefactor se agradece; el resto del año suele bastar con las frazadas.

        Ofrece primero las FRAZADAS —son gratis y se piden por el chat— y deja el calefactor como la opción de quien quiere la habitación templada, no sólo la cama caliente. NO prometas que no lo va a necesitar: si pasa frío la primera noche, esa frase se le queda.

        ⚠️ LAS FRAZADAS SON POCAS. Ofrécelas SÓLO si el huésped ha hablado de frío; nunca como cortesía en una conversación de otra cosa. No prometas una cantidad ni «las que necesite»: se piden con antelación y se dejan preparadas, y hay que poder cumplirlo con todas las casitas ocupadas.
        TXT;

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

        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['codigo' => self::CODIGO]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error('No existe la ficha «calefactor».');

            return Command::FAILURE;
        }

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

        $cuerpo = (string) ($descripcion[$indice]['content'] ?? '');
        $agente = (string) $item->getAgenteContenido();
        $tocado = false;

        if (str_contains($cuerpo, 'Motivo del costo')) {
            if (!str_contains($cuerpo, self::MOTIVO_EN_GUIA)) {
                // A ciegas no: si el párrafo se editó, quitarlo por aproximación se llevaría por
                // delante algo que alguien escribió a mano.
                $io->error('El párrafo del motivo no está tal cual: míralo en el panel.');

                return Command::FAILURE;
            }

            $io->text('<fg=red>- de la guía: el motivo del costo (kWh, consumo, comparación)</>');

            if (!$simular) {
                $descripcion[$indice]['content'] = trim(str_replace(self::MOTIVO_EN_GUIA, '', $cuerpo));
                $item->setDescripcion([['language' => 'es', 'content' => $descripcion[$indice]['content']]]);
            }

            $tocado = true;
        }

        if (!str_contains($cuerpo, 'frazadas adicionales sin costo')) {
            $io->text('<fg=green>+ a la guía: las frazadas, aquí y no en la ficha de toallas</>');

            if (!$simular) {
                $cuerpo = trim(($descripcion[$indice]['content'] ?? $cuerpo) . "\n" . self::FRAZADAS_EN_GUIA);
                $item->setDescripcion([['language' => 'es', 'content' => $cuerpo]]);
            }

            $tocado = true;
        }

        if (!str_contains($agente, 'SI PREGUNTA SI HARÁ FALTA')) {
            $io->text('<fg=green>+ al agente: por qué cuesta lo que cuesta, y si hará falta</>');

            if (!$simular) {
                $item->setAgenteContenido(trim($agente . "\n\n" . self::AGENTE));
            }

            $tocado = true;
        }

        if (!$tocado) {
            $io->success('Ya estaba.');

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
