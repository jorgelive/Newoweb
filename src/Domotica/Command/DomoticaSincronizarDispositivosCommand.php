<?php

declare(strict_types=1);

namespace App\Domotica\Command;

use App\Domotica\Entity\DomoticaDispositivo;
use App\Domotica\Repository\DomoticaDispositivoRepository;
use App\Exchange\Service\Client\TuyaClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Da de alta los aparatos del proyecto Tuya, y les pregunta a ELLOS qué saben hacer.
 *
 * ── Por qué esto no es un formulario ────────────────────────────────────────
 *
 * `mideConsumo` y `conmutable` son campos DERIVADOS: si la especificación del aparato expone
 * `add_ele` mide, si expone `switch_1` conmuta. `Version20260815020000` los hace nacer en `false`
 * justo para que nadie los teclee, y el parque real demostró por qué — de los 28 aparatos, **tres
 * son detectores de gas**. Un alta con las casillas marcadas por defecto los habría metido en el
 * muestreo y en las alertas cada tres horas.
 *
 * ── Qué respeta y qué no ────────────────────────────────────────────────────
 *
 * Idempotente por `tuyaDeviceId`, que es la clave natural. Al re-ejecutarse **refresca las
 * capacidades** —eso lo manda el aparato— pero NO pisa lo que decide una persona: la unidad
 * asignada, el `activo` y las notas se quedan como estén. El nombre se copia de Tuya sólo al dar
 * de alta, porque después puede haberse renombrado aquí a propósito.
 */
#[AsCommand(
    name: 'app:domotica:sincronizar-dispositivos',
    description: 'Da de alta los aparatos de Tuya y refresca lo que sabe hacer cada uno.'
)]
final class DomoticaSincronizarDispositivosCommand extends Command
{
    public function __construct(
        private readonly TuyaClient $tuya,
        private readonly DomoticaDispositivoRepository $dispositivos,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('renombrar', null, InputOption::VALUE_NONE, 'Trae también los nombres de Tuya, pisando los de aquí.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice qué haría, sin escribir.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $renombrar = (bool) $input->getOption('renombrar');

        $remotos = $this->tuya->listarDispositivos();

        if ($remotos === []) {
            $io->warning('Tuya no devolvió ningún aparato.');

            return Command::SUCCESS;
        }

        $altas = 0;
        $actualizados = 0;
        $filas = [];

        foreach ($remotos as $remoto) {
            $codigos = $this->tuya->codigosDeEstado($remoto['id']);
            $mide = in_array('add_ele', $codigos, true);
            $conmuta = in_array('switch_1', $codigos, true);

            $dispositivo = $this->dispositivos->porTuyaId($remoto['id']);
            $esAlta = $dispositivo === null;

            if ($esAlta) {
                $dispositivo = new DomoticaDispositivo();
                $dispositivo->setTuyaDeviceId($remoto['id']);
                // El nombre se copia SÓLO al dar de alta: luego puede haberse cambiado aquí a
                // propósito, y pisarlo en cada sincronización sería deshacer ese trabajo.
                $dispositivo->setNombre($remoto['nombre']);
                // Nace inactivo: que exista en Tuya no significa que queramos muestrearlo. Lo
                // decide una persona al asignarle unidad — `activo` es «queremos», `mideConsumo`
                // es «puede».
                $dispositivo->setActivo(false);
                $altas++;
            } else {
                $actualizados++;

                // El nombre NO se pisa por defecto: aquí puede haberse puesto uno mejor que el de
                // la app del móvil, y una sincronización rutinaria no debe deshacer ese trabajo.
                // Con --renombrar sí, que es lo que hace falta cuando el rename ocurrió allí a
                // propósito — como al marcar una estufa poniéndole «E » delante.
                if ($renombrar && $dispositivo->getNombre() !== $remoto['nombre']) {
                    $io->text(sprintf('  renombrado: «%s» → «%s»', $dispositivo->getNombre(), $remoto['nombre']));
                    $dispositivo->setNombre($remoto['nombre']);
                }
            }

            $dispositivo->setMideConsumo($mide);
            $dispositivo->setConmutable($conmuta);
            $dispositivo->setEnLinea($remoto['enLinea']);

            if (!$seco && $esAlta) {
                $this->em->persist($dispositivo);
            }

            $filas[] = [
                $esAlta ? 'ALTA' : '·',
                mb_substr($remoto['nombre'], 0, 26),
                $mide ? 'mide' : '',
                $conmuta ? 'conmuta' : '',
                $remoto['enLinea'] ? 'en línea' : 'desconectado',
            ];
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->table(['', 'Aparato', '', '', 'Estado'], $filas);

        $io->success(sprintf(
            '%d alta(s), %d actualizado(s). Miden %d de %d.%s',
            $altas,
            $actualizados,
            count(array_filter($filas, static fn (array $f): bool => $f[2] === 'mide')),
            count($filas),
            $seco ? ' Nada escrito (--dry-run).' : ''
        ));

        if ($altas > 0 && !$seco) {
            $io->note('Las altas quedan INACTIVAS y sin unidad. Asignarlas antes de que el muestreo las mire.');
        }

        return Command::SUCCESS;
    }
}
