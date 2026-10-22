<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone al día la ficha «Plancha y secadora» (22/10/2026, Jorge).
 *
 * - La SECADORA de cabello ya se deja siempre en la casita: no se pide.
 * - La PLANCHA y la tabla se piden de un día para otro.
 *
 * ── El caso ──────────────────────────────────────────────────────────────────
 * José Paredes (AG2CZY) pidió la plancha a las 17:54 «para poder salir». La ficha decía «que la
 * pida por el chat y se la llevamos», y el agente se lo prometió: a esa hora el personal ya no
 * estaba en la zona y lo acabó resolviendo Jorge. Ahora el agente sabe la hora
 * (`AiConversationProcessor::contexto()`) y la ficha dice cuándo se lleva y qué hacer si es para hoy.
 *
 * Reescribe el ESPAÑOL de `descripcion` (el listener traduce los demás idiomas) y `agenteContenido`.
 * Pisa lo editado en el panel a propósito: es un cambio de regla. Con `--dry-run`. Nace `hidden`.
 */
#[AsCommand(
    name: 'app:pms:guia:plancha-y-secadora',
    description: 'Pone al día la ficha «Plancha y secadora»: secadora siempre en la casita, plancha de un día para otro.',
    hidden: true,
)]
final class PmsGuiaPlanchaYSecadoraCommand extends Command
{
    private const string CODIGO = 'plancha-y-secadora';

    private const string NOMBRE = 'Plancha y secadora (general)';

    private const string DESCRIPCION = '<p>🌬️💇‍♀️ Tu casita tiene <strong>secadora de cabello</strong>.</p>'
        . '<p>👔 Si necesitas planchar, pídenos la <strong>plancha y la tabla de planchar</strong> por el chat '
        . '<strong>con un día de anticipación</strong> y te las llevamos.</p>'
        . '<p>⚠️ La plancha se usa sobre la <strong>tabla de planchar</strong> y la secadora de cabello '
        . '<strong>solo en el baño</strong>, nunca sobre la cama ni los muebles.</p>';

    private const string AGENTE = 'SECADORA DE CABELLO: ya está en la casita, no hay que pedirla. Si dice que no la '
        . 'encuentra, escala para que el equipo le diga dónde está. '
        . 'PLANCHA Y TABLA DE PLANCHAR: se piden de un día para otro y el personal las lleva al día siguiente. '
        . 'Anótalo con anotar_peticion y dile que se la llevan mañana. '
        . 'Si la necesita HOY (para salir esa noche, una cena…), no le prometas que se la llevan: escala con '
        . 'escalar_al_equipo diciendo que la necesita hoy y a qué hora, y dile que el equipo le confirma si hay forma. '
        . 'Si va a planchar, recuérdale que la plancha se usa sobre la tabla y la secadora sólo en el baño, como '
        . 'recomendación, no como reprimenda.';

    private const string TITULO = 'Secadora de cabello y plancha para ropa';

    /**
     * Como lo pregunta la gente (`docs/Mensajeria.md` §22). La ficha no tenía ninguno, y el índice de
     * temas sólo la encontraba por el título: en el ensayo, «¿la secadora hay que pedirla?» acabó en
     * «la guía no dice si hay secador».
     */
    private const string TERMINOS = 'secadora, secador, secador de pelo, secadora de cabello, hair dryer, '
        . 'blow dryer, plancha, planchar, planchar la ropa, tabla de planchar, iron, ironing board';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Sólo dice qué haría.')] bool $dryRun = false,
    ): int {
        // Por código y, si no lo tiene (bases donde no pasó `PmsGuiaCodigosCommand`), por su nombre.
        $repo = $this->em->getRepository(PmsGuiaItem::class);
        $item = $repo->findOneBy(['codigo' => self::CODIGO]) ?? $repo->findOneBy(['nombreInterno' => self::NOMBRE]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No existe la ficha «%s».', self::CODIGO));

            return Command::FAILURE;
        }

        $io->section('Al huésped (es)');
        $io->writeln(self::DESCRIPCION);
        $io->section('Al agente');
        $io->writeln(self::AGENTE);

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // Sólo el español: con el resto fuera, el listener vuelve a traducir los seis idiomas.
        $item->setTitulo([['language' => 'es', 'content' => self::TITULO]]);
        $item->setDescripcion([['language' => 'es', 'content' => self::DESCRIPCION]]);
        $item->setAgenteContenido(self::AGENTE);
        $item->setAgenteTerminos(self::TERMINOS);
        $this->em->flush();

        $io->success(sprintf('«%s» al día, en %d idiomas.', $item->getNombreInterno(), count($item->getDescripcion() ?? [])));

        return Command::SUCCESS;
    }
}
