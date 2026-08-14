<?php

declare(strict_types=1);

namespace App\Energia\Command;

use App\Energia\Repository\EnergiaDispositivoRepository;
use App\Energia\Service\VigilanteDeDispositivos;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ¿Hay algún enchufe que lleve tiempo sin registrar consumo?
 *
 * ── Por qué es un comando aparte del muestreo ───────────────────────────────
 *
 * El aviso normal lo dispara el propio muestreo: cuando le pide la lectura a un aparato y no
 * viene nada, `VigilanteDeDispositivos::anotarFallo()` suma y avisa cada N fallos. Eso cubre el
 * caso frecuente —el enchufe desconectado, el wifi de la casita caído, la cuota de Tuya agotada—.
 *
 * Lo que NO cubre es que se muera el muestreo entero. Si el cron deja de correr, nadie llama a
 * `anotarFallo()`, el contador se queda congelado en cero y el sistema se queda callado
 * exactamente cuando más falta hace que hable: todos los aparatos mudos a la vez y ni un aviso.
 *
 * Por eso esto va en su propia entrada de crontab y mira el RELOJ en vez del contador. Es la
 * única señal que sobrevive a que el proceso que debía vigilar sea justamente el que falló.
 *
 *   5  * * * *   php bin/console app:energia:muestrear    (pendiente)
 *   35 * * * *   php bin/console app:energia:vigilar
 *
 * Desfasado media hora del muestreo a propósito: si los dos salen juntos, el barrido lee el
 * estado de antes de que el muestreo lo actualice y avisa de aparatos que están bien.
 */
#[AsCommand(
    name: 'app:energia:vigilar',
    description: 'Avisa de los enchufes que llevan horas sin registrar consumo.'
)]
final class EnergiaVigilarCommand extends Command
{
    public function __construct(
        private readonly VigilanteDeDispositivos $vigilante,
        private readonly EnergiaDispositivoRepository $dispositivos,
        private readonly EntityManagerInterface $em,
        #[Autowire('%energia.horas_para_alerta%')] private readonly int $horasPorDefecto,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('horas', null, InputOption::VALUE_REQUIRED, 'Horas sin lectura para considerarlo mudo.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice a quién avisaría, sin avisar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $horas = (int) ($input->getOption('horas') ?? 0) ?: $this->horasPorDefecto;
        $seco = (bool) $input->getOption('dry-run');

        if ($seco) {
            $mudos = $this->dispositivos->mudos($horas);

            foreach ($mudos as $dispositivo) {
                $io->text(sprintf(
                    '· %s — última lectura %s%s',
                    (string) $dispositivo,
                    $dispositivo->getLecturaTomadaEn()?->format('d/m H:i') ?? 'nunca',
                    $dispositivo->getFallosConsecutivos() > 0
                        ? sprintf(' (ya lo avisa el muestreo: %d fallos)', $dispositivo->getFallosConsecutivos())
                        : ''
                ));
            }

            $io->success(sprintf('%d mudo(s) con %d h de margen. Nada enviado (--dry-run).', count($mudos), $horas));

            return Command::SUCCESS;
        }

        $avisados = $this->vigilante->mudosSinMuestrear($horas);

        if ($avisados !== []) {
            $this->em->flush();
        }

        foreach ($avisados as $dispositivo) {
            $io->warning(sprintf('%s sin lectura desde %s', (string) $dispositivo, $dispositivo->getLecturaTomadaEn()?->format('d/m H:i') ?? 'siempre'));
        }

        $io->success(sprintf('%d aviso(s) enviados.', count($avisados)));

        return Command::SUCCESS;
    }
}
