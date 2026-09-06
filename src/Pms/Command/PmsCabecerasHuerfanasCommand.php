<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Apaga las cabeceras financieras ACTIVAS cuyas estancias están todas canceladas.
 *
 * ### De dónde salen
 *
 * `PmsInformacionFinancieraCoherenciaListener::aplicarCancelacion()` apaga la cabecera cuando
 * la última estancia viva pasa a cancelada — pero miraba el `changeSet`, o sea **sólo la
 * transición**. Una reserva que llega de Beds24 **ya cancelada** se inserta con el estado
 * puesto y no hay «anterior» que comparar, así que su cabecera se quedaba activa. El agujero
 * se cerró el 06/09/2026 cubriendo también las inserciones; este comando limpia lo que quedó
 * de antes.
 *
 * ### Por qué importa, aunque las nueve de julio fueran inofensivas
 *
 * Con la cabecera activa sus cargos **siguen sumando**. Las que había en producción tenían
 * `total_cargos` a cero y no dieron la cara, pero con importes de verdad
 * `PmsPrepagoEnlaceService::emitirConTurno()` no encuentra su guarda de `isActiva()` y llegaría
 * a **emitir un enlace de cobro sobre una reserva cancelada**.
 *
 * ### Va por ORM, no por SQL
 *
 * `activa` la leen los listeners de coherencia y el recálculo de totales: un `UPDATE` directo
 * se los salta y deja la cabecera apagada con los totales diciendo lo de antes. Es la regla de
 * CLAUDE.md §«Qué entra por migración y qué tiene que entrar por comando».
 *
 * ### ⚠️ Sólo las que no tienen NADA: cargos y pagos a cero
 *
 * Que la cabecera siga activa con todas las estancias canceladas puede ser **una decisión del
 * operador**: el huésped que cancela en la OTA para pasarse a directa y ahorrarse la comisión
 * sigue durmiendo aquí, y hay que cobrarle. Para eso existe «Reactivar cobro» (§12.7 de
 * `PmsBeds24ReservasSync.md`), y apagársela sería quitarle una decisión ya tomada.
 *
 * Por eso este comando **sólo toca las que tienen `total_cargos` y `total_pagos` a cero**: ahí
 * no hay nada que cobrar, así que no hay decisión que proteger. Una cabecera reactivada a
 * propósito tiene cargos por definición — es para lo que se reactiva.
 *
 * Es **idempotente**: una cabecera ya apagada no vuelve a aparecer.
 *
 *   php bin/console app:pms:cabeceras:huerfanas --dry-run
 */
#[AsCommand(
    name: 'app:pms:cabeceras:huerfanas',
    description: 'Apaga las cabeceras financieras activas cuyas estancias están todas canceladas.',
)]
final class PmsCabecerasHuerfanasCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña cuáles apagaría, sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        /** @var list<PmsInformacionFinanciera> $activas */
        $activas = $this->em->getRepository(PmsInformacionFinanciera::class)->findBy(['activa' => true]);

        $huerfanas = [];

        foreach ($activas as $info) {
            $reserva = $info->getReserva();

            if ($reserva === null) {
                continue;
            }

            $estancias = $reserva->getEventosCalendario();

            // Sin ninguna estancia no es una cancelación: es una reserva a medio nacer, y
            // apagarle la cabecera escondería el problema en vez de enseñarlo.
            if (count($estancias) === 0) {
                continue;
            }

            // Con dinero de por medio puede ser un cobro REACTIVADO a mano. Ver la cabecera.
            if ((float) $info->getTotalCargos() > 0.005 || (float) $info->getTotalPagos() > 0.005) {
                continue;
            }

            foreach ($estancias as $evento) {
                if ((string) $evento->getEstado()?->getId() !== PmsEventoEstado::CODIGO_CANCELADA) {
                    continue 2;
                }
            }

            $huerfanas[] = $info;
        }

        if ($huerfanas === []) {
            $io->success('Ninguna cabecera activa tiene todas sus estancias canceladas.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('%d cabecera(s) a apagar%s', count($huerfanas), $seco ? ' · SIMULACIÓN' : ''));

        $filas = [];

        foreach ($huerfanas as $info) {
            $reserva = $info->getReserva();
            $filas[] = [
                (string) $reserva?->getLocalizador(),
                $reserva?->getFechaLlegada()?->format('Y-m-d') ?? '—',
                $info->getTotalCargos(),
                $info->getTotalPagos(),
                count($reserva?->getEventosCalendario() ?? []),
            ];

            if (!$seco) {
                $info->setActiva(false);
            }
        }

        $io->table(['Localizador', 'Llegada', 'Cargos', 'Pagos', 'Estancias'], $filas);

        if ($seco) {
            $io->note('Simulación: no se apagó ninguna.');

            return Command::SUCCESS;
        }

        // ⚠️ Un `flush()` para todas: cada `setActiva(false)` dispara el recálculo de la
        // cabecera por los listeners de coherencia, que es justamente lo que un UPDATE en SQL
        // se saltaría.
        $this->em->flush();

        $io->success(sprintf('%d cabecera(s) apagada(s).', count($huerfanas)));

        return Command::SUCCESS;
    }
}
