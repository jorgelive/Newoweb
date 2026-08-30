<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Contract\MapaDeHitos;
use App\Pms\Entity\PmsConversacionEnlace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aplana los hitos que quedaron guardados como objeto serializado.
 *
 * ### Qué pasó
 *
 * `PmsReservaMessageContext::getMilestones()` devuelve objetos `DateTimeImmutable`, y el enlace
 * los guardaba tal cual pese a que su contrato decía `array<string, string>`. Al persistirse en
 * la columna JSON quedaron como `{"date": "...", "timezone": "America/Lima", ...}`.
 *
 * Al releerlos, el motor de reglas recibe un array donde esperaba texto y **el asunto entero se
 * salta**: esa reserva se queda sin ninguno de sus recordatorios —ni bienvenida, ni guía de
 * llegada, ni check-out—, con un `ERROR` en el log como única señal.
 *
 * El origen ya está cerrado en `PmsConversacionEnlace::setMilestones()`, que ahora normaliza las
 * tres formas. Esto repara lo que quedó escrito antes.
 *
 * ### Por qué comando y no migración
 *
 * La reparación es exactamente «volver a guardar por el ORM»: el normalizador de la entidad hace
 * el trabajo, así que no hay que replicar en SQL una lógica que ya existe —ni arriesgarse a que
 * las dos se separen—. Ver CLAUDE.md §Qué entra por migración y qué por comando.
 *
 * Es idempotente: los enlaces ya sanos se detectan y no se tocan.
 */
#[AsCommand(
    name: 'app:pms:reparar-hitos',
    description: 'Aplana los hitos guardados como objeto serializado, que dejan reservas sin recordatorios.'
)]
final class PmsRepararHitosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué repararía.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $reparados = 0;

        /** @var list<PmsConversacionEnlace> $enlaces */
        $enlaces = $this->em->getRepository(PmsConversacionEnlace::class)->findAll();

        foreach ($enlaces as $enlace) {
            // ⚠️ Por el getter CRUDO, no por `getMilestones()`. Ése declara
            // `array<string, string>`, y con esa promesa el analizador da por imposible
            // justamente el caso que este comando busca: marcaba todo lo de abajo como código
            // muerto. El filtro sí funcionaba en ejecución — el getter no normaliza nada—, pero
            // un comando que estáticamente no hace nada es un comando que nadie se atreve a
            // tocar.
            $hitos = $enlace->getMilestonesCrudo();
            $sucios = array_filter($hitos, static fn ($v): bool => !is_string($v));

            if ($sucios === []) {
                continue;
            }

            $io->text(sprintf(
                '· enlace %s — %s',
                $enlace->getId(),
                implode(', ', array_keys($sucios))
            ));

            ++$reparados;

            if (!$seco) {
                // El normalizador de la entidad hace el trabajo: basta con devolverle lo que
                // había leído.
                $enlace->setMilestones(MapaDeHitos::desdeCrudo($hitos));
            }
        }

        if (!$seco) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d enlace(s) %s de %d revisados.',
            $reparados,
            $seco ? 'se repararían' : 'reparados',
            count($enlaces)
        ));

        return Command::SUCCESS;
    }
}
