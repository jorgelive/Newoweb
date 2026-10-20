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
 * La puerta de la Casita 4 se abre tirando del jalador, y el agente tiene que insistir en eso
 * antes de escalar (20/10/2026).
 *
 * ### El caso
 *
 * Pinas Trojan, en la puerta: «la llave se atasca». Acababa de recibir el truco —tirar de la puerta
 * mientras gira la llave— y el agente escaló al equipo cuatro veces, con el teléfono de urgencias
 * sin contestar, en vez de insistir. Entraron solos a los veinte minutos. La solución era la de
 * siempre: comprobar que tienen la llave 4 y tirar de la puerta con el jalador.
 *
 * ### Qué hace
 *
 * - **La escalera** (`agentePasos`), como la de la ducha: peldaño 1, la llave 4 y el jalador, dicho
 *   con seguridad; peldaño 2, si aun así no abre, emergencia al equipo contando lo que ya probó. La
 *   red de `escalar_al_equipo` (`EscaleraDeTemas::pendienteReciente()`) es la que obliga a pasar
 *   por el peldaño antes de avisar.
 * - **El truco con el jalador**, en las dos superficies que lo cuentan: el texto del agente y el
 *   publicado. Decían «tira suavemente de la puerta»: lo que abre es agarrar el jalador y tirar
 *   mientras se gira. El publicado va por el ORM y `AutoTranslationEventListener` lo traduce.
 *
 * Idempotente y con `--dry-run`: cada cambio sólo se aplica si no está ya.
 */
#[AsCommand(
    name: 'app:pms:guia:puerta-jalador',
    description: 'Puerta de la Casita 4: escalera «llave 4 + jalador» y el truco del jalador en la guía.',
)]
final class PmsGuiaPuertaJaladorCommand extends Command
{
    private const string FICHA = 'Puerta (casa 4)';

    private const string TRUCO_VIEJO = 'tira suavemente de la puerta hacia ti mientras giras la llave y abrirá sin forzar.';

    private const string TRUCO_NUEVO = 'agarra el jalador y tira de la puerta hacia ti mientras giras la llave: así abre sin forzar.';

    private const string PASO_LLAVE_Y_JALADOR = <<<'TXT'
        SI DICE QUE NO ABRE, QUE LA LLAVE SE ATASCA O NO GIRA: casi siempre es que no está tirando de la puerta. Que compruebe estas dos cosas, en este orden:
        1) Que tiene la llave número 4. Las llaves llevan su número, y en la caja fuerte hay de otras casitas.
        2) Que agarre el jalador de la puerta y tire de ella hacia sí con firmeza MIENTRAS gira la llave en sentido horario. Sin tirar, la cerradura roza y la llave parece atascada; tirando del jalador, gira.

        Díselo con seguridad, como la solución que es, y pídele que te diga si así abre. La fuerza va en el jalador, no en la llave: que no la fuerce.
        TXT;

    private const string PASO_AVISAR = <<<'TXT'
        Si ya tiene la llave 4 y tirando del jalador sigue sin abrir: avisa al equipo como emergencia con escalar_al_equipo, contando que ya comprobó la llave 4 y que tiró del jalador mientras giraba, y dale el teléfono de emergencias. Mientras, que no fuerce la llave para que no se rompa dentro.
        TXT;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Sólo dice qué haría.')]
        bool $dryRun = false,
    ): int {
        $item = $this->em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => self::FICHA]);

        if (!$item instanceof PmsGuiaItem) {
            $io->error(sprintf('No encuentro «%s».', self::FICHA));

            return Command::FAILURE;
        }

        $hecho = [];

        $agente = (string) $item->getAgenteContenido();
        if (str_contains($agente, self::TRUCO_VIEJO)) {
            $hecho[] = 'texto del agente: el truco con el jalador';
            $dryRun || $item->setAgenteContenido(str_replace(self::TRUCO_VIEJO, self::TRUCO_NUEVO, $agente));
        }

        $descripcion = $item->getDescripcion() ?? [];
        foreach ($descripcion as $i => $traduccion) {
            if (($traduccion['language'] ?? null) === 'es' && str_contains((string) ($traduccion['content'] ?? ''), self::TRUCO_VIEJO)) {
                $descripcion[$i]['content'] = str_replace(self::TRUCO_VIEJO, self::TRUCO_NUEVO, (string) $traduccion['content']);
                $hecho[] = 'texto publicado (se retraduce solo)';
                $dryRun || $item->setDescripcion($descripcion);
            }
        }

        if ($item->getAgentePasos() === []) {
            $hecho[] = 'escalera: llave 4 + jalador → avisar';
            $dryRun || $item->setAgentePasos([self::PASO_LLAVE_Y_JALADOR, self::PASO_AVISAR]);
        }

        if ($hecho === []) {
            $io->success('Nada que cambiar: ya estaba.');

            return Command::SUCCESS;
        }

        $io->listing($hecho);

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success($dryRun ? 'Esto es lo que se cambiaría.' : 'Hecho.');

        return Command::SUCCESS;
    }
}
