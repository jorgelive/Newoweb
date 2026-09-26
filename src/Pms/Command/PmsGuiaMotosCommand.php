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
 * Las motos en el pasaje: la pregunta de siempre, contestada donde el huésped la hace.
 *
 * ── El caso ─────────────────────────────────────────────────────────────────
 * 25/09/2026, ocho de la noche. Un huésped acaba de llegar: «¿podemos dejar las motos en el
 * pasillo?». El agente **escaló** y le dejó esperando con las motos en la calle. Dedujo por su
 * cuenta que el pasaje es zona de paso y evacuación —cierto, y no estaba escrito en ninguna
 * parte— pero no tenía la respuesta, que existe desde siempre.
 *
 * ⚠️ El motivo que yo escribí primero —«no caben, hay maceteros»— no era el bueno. Jorge lo
 * corrigió: lo que manda es que **quedarían muy expuestas** —de ahí la recomendación de una
 * cochera vigilada— y que **el pasaje no es de uso exclusivo nuestro**, se comparte con los
 * vecinos. El segundo no admite excepción ni depende del tamaño de la moto, que es justo lo que
 * mi versión dejaba abierto a discusión.
 *
 * ── Por qué también aquí, si ya está en el conocimiento ─────────────────────
 * Porque son dos públicos con dos caminos. Al añadirlo sólo a la ficha de conocimiento se
 * reprodujo la pregunta y el agente contestó desde la GUÍA —que es lo que el triaje elige para
 * un huésped con reserva— con el texto viejo: dijo «no está permitido» y se quedó sin el motivo
 * ni la recomendación. El conocimiento sirve a quien todavía no ha reservado; la guía, a quien
 * ya está en la puerta con la moto.
 *
 * Es la misma frontera que ya estaba escrita en `ValidadorDeConocimiento`, vista desde el otro
 * lado: para el huésped manda su guía.
 *
 * ── Y el sitio va con nombre y mapa ─────────────────────────────────────────
 * Garaje de Saphy, Calle Saphy 644. Mandar a alguien «a una cochera a una cuadra» con una moto
 * cargada y de noche es mandarlo a buscar.
 *
 * Nace `hidden` porque es de una vez. Idempotente por contenido.
 */
#[AsCommand(
    name: 'app:pms:guia:motos',
    description: 'Añade a la ficha de estacionamiento qué hacer con las motos. Idempotente.',
    hidden: true,
)]
final class PmsGuiaMotosCommand extends Command
{
    private const string CODIGO = 'estacionamiento';

    /**
     * El párrafo de la cochera, que estaba sin nombre.
     *
     * Decía «a una cuadra hay una cochera privada de pago» y cuatro líneas más abajo esa misma
     * cochera aparecía con nombre, calle y mapa. Quien lee de arriba abajo la ve dos veces y la
     * primera no le sirve para llegar; y quien viene en coche —no en moto— se quedaba sólo con
     * la versión anónima.
     */
    private const string COCHERA_SIN_NOMBRE = '<p>🔒 A <strong>una cuadra</strong> hay una cochera '
        . 'privada <strong>de pago</strong>, si prefieres dejarlo bajo vigilancia.</p>';

    private const string COCHERA = '<p>🔒 A <strong>una cuadra</strong> está el '
        . '<strong>Garaje de Saphy</strong> (Calle Saphy 644, teléfono +51 984 631 997), una '
        . 'cochera <strong>de pago y vigilada</strong>, si prefieres dejarlo bajo techo.<br>'
        . '📍 <a href="https://maps.app.goo.gl/rvxnSoKnNmtwuh5e7">Ver ubicación</a></p>';

    /**
     * Para el agente. Lleva el motivo antes que la negativa y la alternativa pegada.
     *
     * ⚠️ «No escales esto» va explícito porque es lo que hizo, y lo hizo razonablemente: sin
     * respuesta escrita, preguntar a una persona es lo prudente. La instrucción sólo vale
     * acompañada del dato — decirle que no escale sin darle qué contestar sería peor.
     */
    private const string AGENTE_PRIMERA = <<<'TXT'
        MOTOS. Casi todos los que llegan en moto miran el pasaje y piensan que pueden dejarla ahí, así que espera la pregunta. NO CABE, y ése es el motivo: las motos de viaje son enormes, y aunque fueran pequeñas el pasaje tiene maceteros y plantas. Además es zona de paso y de evacuación, que no puede quedar obstaculizada.

        Dilo por lo que es —no entra— y no como una prohibición. Y ofrécele en el acto la cochera privada de pago de a una cuadra: para una moto es lo que recomendamos siempre, porque el estacionamiento público de enfrente no es vigilado y ahí queda demasiado expuesta. NO escales esto: la respuesta es ésta y no depende de nadie.
        TXT;

    /** Las dos redacciones anteriores, para poder reconocerlas y sustituirlas. */
    private const string HUESPED_SEGUNDA = '<p>🏍️ <strong>¿Y las motocicletas?</strong> No es posible '
        . 'dejarlas en el pasillo. Por motivos de seguridad y dado que quedarían muy expuestas, '
        . 'nuestra recomendación es que las resguarden en una cochera vigilada para su total '
        . 'tranquilidad. Además, el pasaje no es de uso exclusivo nuestro y debemos mantener el '
        . 'área despejada para evitar cualquier incomodidad a los vecinos con quienes '
        . 'compartimos el espacio.</p>'
        . '<p>Les sugerimos el <strong>Garaje de Saphy</strong>, el estacionamiento más cercano: '
        . 'Calle Saphy 644, teléfono +51 984 631 997.<br>'
        . '📍 <a href="https://maps.app.goo.gl/rvxnSoKnNmtwuh5e7">Ver ubicación</a></p>';

    private const string HUESPED_PRIMERA = '<p>🏍️ <strong>¿Y las motos?</strong> En el pasaje no caben '
        . '—las de viaje son grandes y además hay maceteros, y es zona de paso y evacuación—, '
        . 'así que para una moto te recomendamos la <strong>cochera privada</strong> de a una '
        . 'cuadra: el estacionamiento de enfrente no es vigilado y ahí queda muy expuesta.</p>';

    /**
     * La versión de Jorge, con los datos que faltaban.
     *
     * Dos cosas que yo no tenía y cambian la respuesta: **el pasaje no es de uso exclusivo
     * nuestro** —se comparte con vecinos, y ése es un motivo que no admite excepción ni depende
     * del tamaño de la moto— y la cochera **tiene nombre, dirección y mapa**: Garaje de Saphy,
     * Calle Saphy 644. Mandar a alguien «a una cochera a una cuadra» con una moto cargada, de
     * noche, es mandarlo a buscar.
     *
     * El orden también es suyo y es mejor: primero la seguridad de la moto —que es lo que al
     * huésped le importa— y después lo de los vecinos. Al revés sonaría a que el problema es
     * nuestro y él el estorbo.
     */
    private const string AGENTE = <<<'TXT'
        MOTOS. Casi todos los que llegan en moto miran el pasaje y piensan que pueden dejarla ahí, así que espera la pregunta. La respuesta es que no, y NO ESCALES: no depende de nadie.

        Dos motivos, en este orden. Primero, quedarían muy expuestas: la recomendación es que las resguarden en una cochera vigilada, por su tranquilidad. Y segundo, el pasaje NO es de uso exclusivo nuestro —se comparte con los vecinos— y hay que mantenerlo despejado.

        Dale el sitio concreto, no «una cochera cerca»: Garaje de Saphy, el estacionamiento más cercano, en Calle Saphy 644, teléfono +51 984 631 997. Y pásale la ubicación: https://maps.app.goo.gl/rvxnSoKnNmtwuh5e7
        TXT;

    /**
     * Para el huésped: SÓLO la recomendación. El pasaje no se menciona.
     *
     * ⚠️ La primera versión explicaba en la guía por qué no se puede dejar la moto en el pasaje,
     * y Jorge lo paró: **en la guía nadie ha preguntado eso**. Mencionarlo mete una idea que el
     * huésped no tenía —«ah, ¿se podía dejar ahí?»— y encima sin el contexto que la haría
     * entenderse, porque quien lee la guía todavía no ha visto el pasaje.
     *
     * El motivo no se pierde: vive en `AGENTE`, que es quien lo necesita, y sale cuando alguien
     * pregunta. Es la misma regla que en los niveles de las casitas: el dato existe, pero no se
     * cuenta de entrada.
     *
     * Y tutea, como el resto de la guía. El texto de Jorge venía del chat —donde le hablaba a un
     * grupo con motos— y ahí el «ustedes» era natural; en una ficha que dice «si prefieres» dos
     * párrafos antes, cambia de tono a mitad.
     */
    private const string HUESPED = '<p>🏍️ <strong>¿Vienes en moto?</strong> Te recomendamos '
        . 'dejarla en el <strong>Garaje de Saphy</strong>, la cochera vigilada de arriba: en la '
        . 'playa gratuita de enfrente quedaría demasiado expuesta.</p>';

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
            $io->error('No existe la ficha «estacionamiento».');

            return Command::FAILURE;
        }

        $agente = (string) $item->getAgenteContenido();
        $cuerpo = '';
        $i18n = $item->getDescripcion() ?? [];

        foreach ($i18n as $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $cuerpo = (string) ($fila['content'] ?? '');
            }
        }

        if (str_contains($agente, 'Garaje de Saphy')
            && str_contains($cuerpo, '¿Vienes en moto?')
            && str_contains($cuerpo, self::COCHERA)
        ) {
            $io->success('Ya lo dice.');

            return Command::SUCCESS;
        }

        // La primera redacción se sustituye entera: decía «no caben» y el motivo real es otro
        // —quedan expuestas y el pasaje se comparte con los vecinos—, y además no daba el sitio
        // con nombre y dirección.
        $agente = str_replace(self::AGENTE_PRIMERA, '', $agente);
        $cuerpo = str_replace([self::HUESPED_PRIMERA, self::HUESPED_SEGUNDA], '', $cuerpo);

        // El nombre sube al párrafo de la cochera: es el que lee quien viene en coche.
        $cuerpo = str_replace(self::COCHERA_SIN_NOMBRE, self::COCHERA, $cuerpo);

        // Y si la versión de motos con dirección ya estaba escrita, se recorta a la corta.
        $cuerpo = str_replace(
            '<p>🏍️ <strong>¿Vienes en moto?</strong> Te recomendamos dejarla en una '
            . '<strong>cochera vigilada</strong>: la más cercana es el <strong>Garaje de '
            . 'Saphy</strong>, en Calle Saphy 644 (teléfono +51 984 631 997). En la playa '
            . 'gratuita de enfrente quedaría demasiado expuesta.<br>'
            . '📍 <a href="https://maps.app.goo.gl/rvxnSoKnNmtwuh5e7">Ver ubicación</a></p>',
            '',
            $cuerpo
        );

        $io->section('Texto del agente');
        $io->writeln(self::AGENTE);
        $io->section('Guía del huésped');
        $io->writeln(strip_tags(self::HUESPED));

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        if (!str_contains($agente, 'Garaje de Saphy')) {
            $item->setAgenteContenido(trim($agente . "\n\n" . self::AGENTE));
        }

        if (!str_contains($cuerpo, '¿Vienes en moto?')) {
            // Sólo el español: el listener rehace los otros seis. Mandar los siete conservados
            // dejaría el párrafo de las motos sólo en castellano.
            $item->setDescripcion([['language' => 'es', 'content' => trim($cuerpo . "\n" . self::HUESPED)]]);
        }

        $this->em->flush();

        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }
}
