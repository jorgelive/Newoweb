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
 * Y no es una norma, es física: **las motos de viaje son enormes y el pasaje tiene maceteros y
 * plantas**. Eso convence; «no está permitido» invita a discutir.
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
     * Para el agente. Lleva el motivo antes que la negativa y la alternativa pegada.
     *
     * ⚠️ «No escales esto» va explícito porque es lo que hizo, y lo hizo razonablemente: sin
     * respuesta escrita, preguntar a una persona es lo prudente. La instrucción sólo vale
     * acompañada del dato — decirle que no escale sin darle qué contestar sería peor.
     */
    private const string AGENTE = <<<'TXT'
        MOTOS. Casi todos los que llegan en moto miran el pasaje y piensan que pueden dejarla ahí, así que espera la pregunta. NO CABE, y ése es el motivo: las motos de viaje son enormes, y aunque fueran pequeñas el pasaje tiene maceteros y plantas. Además es zona de paso y de evacuación, que no puede quedar obstaculizada.

        Dilo por lo que es —no entra— y no como una prohibición. Y ofrécele en el acto la cochera privada de pago de a una cuadra: para una moto es lo que recomendamos siempre, porque el estacionamiento público de enfrente no es vigilado y ahí queda demasiado expuesta. NO escales esto: la respuesta es ésta y no depende de nadie.
        TXT;

    /** Para el huésped, en su guía. Más corto: le basta saber dónde sí y por qué no ahí. */
    private const string HUESPED = '<p>🏍️ <strong>¿Y las motos?</strong> En el pasaje no caben '
        . '—las de viaje son grandes y además hay maceteros, y es zona de paso y evacuación—, '
        . 'así que para una moto te recomendamos la <strong>cochera privada</strong> de a una '
        . 'cuadra: el estacionamiento de enfrente no es vigilado y ahí queda muy expuesta.</p>';

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
        $i18n = $item->getDescripcion();

        foreach ($i18n as $fila) {
            if (($fila['language'] ?? null) === 'es') {
                $cuerpo = (string) ($fila['content'] ?? '');
            }
        }

        if (str_contains($agente, 'MOTOS.') && str_contains($cuerpo, '¿Y las motos?')) {
            $io->success('Ya lo dice.');

            return Command::SUCCESS;
        }

        $io->section('Texto del agente');
        $io->writeln(self::AGENTE);
        $io->section('Guía del huésped');
        $io->writeln(strip_tags(self::HUESPED));

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        if (!str_contains($agente, 'MOTOS.')) {
            $item->setAgenteContenido(trim($agente . "\n\n" . self::AGENTE));
        }

        if (!str_contains($cuerpo, '¿Y las motos?')) {
            // Sólo el español: el listener rehace los otros seis. Mandar los siete conservados
            // dejaría el párrafo de las motos sólo en castellano.
            $item->setDescripcion([['language' => 'es', 'content' => trim($cuerpo . "\n" . self::HUESPED)]]);
        }

        $this->em->flush();

        $io->success('Hecho. Los otros seis idiomas los rehace AutoTranslate al guardar.');

        return Command::SUCCESS;
    }
}
