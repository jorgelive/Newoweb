<?php

declare(strict_types=1);

namespace App\Domotica\Command;

use App\Domotica\Repository\DomoticaDispositivoRepository;
use App\Pms\Entity\PmsUnidad;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ata los aparatos del parque a su casita, según el mapeo acordado el 08/09/2026.
 *
 * ── Por qué esto es un comando y no veintitrés órdenes a mano ───────────────
 *
 * La asignación se decidió una vez, mirando los nombres de Tuya uno por uno. Repetirla a mano en
 * producción sería volver a decidirla —con otra persona, otro día y otro criterio— y un aparato
 * mal atado no da error: le enseña a un huésped el consumo de otra casa y le emite una factura
 * ajena, las dos cosas en silencio. Escrita aquí, la decisión se aplica igual en todas partes y
 * queda leída por PHPStan y `lint:container`.
 *
 * Va por ORM y no por SQL porque es la regla de `CLAUDE.md`: lo que pasa por listeners al guardarse
 * entra por el ORM. Es idempotente por `tuyaDeviceId` y lleva `--dry-run`.
 *
 * ── El criterio, para que se pueda discutir ─────────────────────────────────
 *
 * Los nombres de Tuya llevan el ordinal de la casa («E 6to Matrimonial J», «7mo departamento»), y
 * hay siete ordinales para siete casitas. **`N-ésimo` → `Casita N`.**
 *
 * ⚠️ **Cinco aparatos NO se atan, a propósito**: `Nivel Tanque`, `Corredor`, `Lampara Cocina`,
 * `#sta Mónica` y `Deshumidificador`. No llevan ordinal, así que colocarlos sería adivinar. Un
 * aparato sin unidad es invisible para `pax` —que es lo correcto mientras no se sepa de quién es—
 * pero sigue viéndose en el monitor interno. El del deshumidificador escuece porque **mide**: es
 * uno de los tres contómetros del parque, parado hasta que alguien diga en qué casa está.
 */
#[AsCommand(
    name: 'app:domotica:cargar-mapeo-casitas',
    description: 'Ata cada aparato de Tuya a su casita según el ordinal de su nombre.'
)]
final class DomoticaCargarMapeoCasitasCommand extends Command
{
    /**
     * El mapeo acordado. Por `tuyaDeviceId` y no por nombre: el nombre se puede cambiar desde la
     * app del móvil sin que nadie se entere, y entonces el cargador dejaría de encontrar aparatos
     * en silencio.
     *
     * @var array<string, array{0: string, 1: string}> id de Tuya → [casita, nombre para el log]
     */
    private const MAPEO = [
        '1751535534ab950efecf'   => ['Casita 1', '1er Departamento'],
        'eb55448fc86d1e26e9rv2r' => ['Casita 1', 'E 1ro Adelante'],
        'eb560dd3b90bca9b790p5c' => ['Casita 1', 'Detector 1ro'],
        'eb4776d865f5b3351angr3' => ['Casita 2', 'E 2do triple'],
        'ebe0358675495d49eby8e1' => ['Casita 2', '#1 hab 2do fondo'],
        'eb13e15b93ad1fab85ekk7' => ['Casita 3', '3er depa nuevo'],
        '0863360434ab950d32da'   => ['Casita 3', '3er departamento'],
        'eba81e1e3020e1043d5ych' => ['Casita 3', 'E 3ro Fondo J'],
        'eb54212b7f683f2bf6jbzy' => ['Casita 3', 'E 3ro AdelanteJ'],
        // Sin ordinal en el nombre: la casita la dijo quien sabe dónde está, el 08/09/2026.
        // Es INTERNO — mide, pero no se le enseña al huésped: no es suyo ni lo maneja él.
        'eb9c224677bcb41d50adqd' => ['Casita 3', 'Deshumidificador'],
        '0863360434ab950d2815'   => ['Casita 4', '4to departamento'],
        'eb7292b644e334c429uq41' => ['Casita 4', 'E 4to Jorge 12/07'],
        '0863360434ab950cc69e'   => ['Casita 5', '5to departamento'],
        'eb1a0f0d5ea0c2f7dbcsvm' => ['Casita 5', 'E 5to Habitación'],
        'ebc294df731f2e1d1fwsp0' => ['Casita 6', '6to departamento'],
        'ebc27e2db12155b8f7j1ru' => ['Casita 6', 'E 6to Matrimonial J'],
        'eb8de5f385dc2af3dap1hx' => ['Casita 6', 'E 6to Piedras J'],
        'eb9024c459266396a2ay4w' => ['Casita 6', 'E 6to Rampa J'],
        'eb3ddce1e496e46972bpim' => ['Casita 6', 'Detector 6to'],
        'eb366bb92153276d99tvnj' => ['Casita 7', '7mo departamento'],
        'ebcafde7935522d6a1idky' => ['Casita 7', 'E 7mo Piedra'],
        'eb1dd420c5241a718fpo0i' => ['Casita 7', 'E 7mo triple'],
        'eb6fc33235cab54959rzdk' => ['Casita 7', 'E 7mo triple nuevo'],
        'ebc635c3faebc6eed4tmvs' => ['Casita 7', 'Detector 7mo'],
    ];

    public function __construct(
        private readonly DomoticaDispositivoRepository $dispositivos,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice qué haría, sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var array<string, PmsUnidad> $casitas */
        $casitas = [];

        foreach ($this->em->getRepository(PmsUnidad::class)->findAll() as $u) {
            $nombre = $u->getNombre();

            if (is_string($nombre)) {
                $casitas[$nombre] = $u;
            }
        }

        $atados = 0;
        $yaEstaban = 0;
        $ausentes = [];
        $conflictos = [];

        foreach (self::MAPEO as $tuyaId => [$casita, $etiqueta]) {
            $dispositivo = $this->dispositivos->porTuyaId($tuyaId);

            if ($dispositivo === null) {
                $ausentes[] = $etiqueta;

                continue;
            }

            $unidad = $casitas[$casita] ?? null;

            if ($unidad === null) {
                $io->error(sprintf('No existe la unidad «%s». ¿Se renombró?', $casita));

                return Command::FAILURE;
            }

            $actual = $dispositivo->getUnidad();

            if ($actual !== null && $actual->getId()?->toRfc4122() === $unidad->getId()?->toRfc4122()) {
                $yaEstaban++;

                continue;
            }

            // Ya atado a OTRA casita: no se pisa en silencio. Puede ser que alguien lo moviera de
            // sitio de verdad, y el cargador no puede saberlo — se avisa y se deja como está.
            if ($actual !== null) {
                $conflictos[] = sprintf('%s: está en %s y el mapeo dice %s', $etiqueta, $actual->getNombre() ?? '?', $casita);

                continue;
            }

            if (!$seco) {
                $dispositivo->setUnidad($unidad);
            }

            $atados++;
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d atado(s), %d ya lo estaban.%s',
            $atados,
            $yaEstaban,
            $seco ? ' Nada escrito (--dry-run).' : ''
        ));

        if ($ausentes !== []) {
            $io->warning(sprintf(
                "%d aparato(s) del mapeo no están en la base. Corre antes app:domotica:sincronizar-dispositivos:\n  · %s",
                count($ausentes),
                implode("\n  · ", $ausentes)
            ));
        }

        if ($conflictos !== []) {
            $io->warning("Atados a otra casita — NO se ha tocado ninguno:\n  · " . implode("\n  · ", $conflictos));
        }

        $io->note('Quedan fuera a propósito: Nivel Tanque, Corredor, Lampara Cocina y #sta Mónica — sin ordinal en el nombre, no se adivinan.');

        return Command::SUCCESS;
    }
}
