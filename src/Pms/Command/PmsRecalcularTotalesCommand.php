<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Service\Finance\PmsInformacionFinancieraRecalculoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rehace los totales por moneda de todas las fichas financieras.
 *
 * ── Por qué hace falta, y cuándo ────────────────────────────────────────────
 * `pms_finanzas_total_moneda` la llena `PmsInformacionFinancieraRecalculoService` en el
 * `postFlush` de cada ficha que se toca. Eso basta para la decisión de estado de pago —el rollup
 * corre inmediatamente antes, así que nunca decide con datos rancios— pero deja **vacías las
 * fichas que nadie ha vuelto a guardar** desde que se creó la tabla.
 *
 * Y hay un consumidor al que eso sí le importa: el **calendario**, que lee la tabla en lote para
 * pintar un mes entero. Una ficha sin filas se pintaría sin cifras, no porque no deba nada sino
 * porque nadie la ha tocado.
 *
 * Se ejecuta:
 *   · una vez tras desplegar la migración `Version20260816010000`;
 *   · y cuando haga falta reconstruir, que es gratis: sólo lee cargos y cobros.
 *
 * ── Idempotente por construcción ────────────────────────────────────────────
 * El recálculo es `DELETE` + `INSERT … SELECT` de lo que hay en las tablas hijas: correrlo dos
 * veces da exactamente lo mismo, y no toca ningún dato de negocio. Por eso no necesita `--aplicar`
 * como los comandos de carga de contenido — no hay nada que revisar antes.
 *
 * ⚠️ **También reescribe `total_cargos`/`total_pagos`**, los escalares del modelo viejo, porque el
 * recalculador sigue escribiendo los dos mientras dure la transición. Es lo mismo que ya calculaba
 * el listener, así que no cambia ninguna cifra; pero conviene saberlo antes de correrlo en
 * producción.
 */
#[AsCommand(
    name: 'pms:finanzas:recalcular-totales',
    description: 'Rehace los totales por moneda de todas las fichas financieras.'
)]
final class PmsRecalcularTotalesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsInformacionFinancieraRecalculoService $recalculo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Dice cuántas fichas hay y cuántas están sin totales, sin escribir nada.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $conn = $this->em->getConnection();

        /** @var list<array{id: string}> $filas */
        $filas = $conn->fetchAllAssociative('SELECT id FROM pms_informacion_financiera');
        $ids = array_map(
            static fn (array $f): string => (string) \Symfony\Component\Uid\Uuid::fromBinary((string) $f['id']),
            $filas,
        );

        // ⚠️ «Movimiento» tiene que significar lo MISMO que cuenta el rollup, o el contador
        // canta un falso positivo cada vez. Una ficha ANULADA con un cargo que no es penalización
        // no aporta nada (§12.7): cero filas es su resultado correcto, no un hueco.
        //
        // Medido: sin este matiz, cuatro fichas anuladas salían siempre como «sin totales» por
        // más veces que se recalculara.
        $sinTotales = (int) $conn->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM pms_informacion_financiera i
            WHERE NOT EXISTS (SELECT 1 FROM pms_finanzas_total_moneda t WHERE t.informacion_id = i.id)
              AND (
                  EXISTS (
                      SELECT 1 FROM pms_cargo_financiero c
                      WHERE c.informacion_id = i.id
                        AND COALESCE(c.tipo, 'charge') = 'charge'
                        AND (i.activa = 1 OR c.tipo_cargo = 'penalizacion')
                  )
               OR EXISTS (SELECT 1 FROM pms_pago_financiero p WHERE p.informacion_id = i.id)
              )
            SQL);

        $io->definitionList(
            ['Fichas' => count($ids)],
            ['Con movimiento COMPUTABLE y sin totales por moneda' => $sinTotales],
        );

        if ($seco) {
            $io->note('Modo seco: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        if ($ids === []) {
            $io->success('No hay fichas que recalcular.');

            return Command::SUCCESS;
        }

        $inicio = microtime(true);
        $this->recalculo->recalcular($ids, $this->em);
        $ms = (microtime(true) - $inicio) * 1000;

        $conMovimiento = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT informacion_id) FROM pms_finanzas_total_moneda',
        );

        $io->success(sprintf(
            '%d fichas recalculadas en %.0f ms. %d tienen totales por moneda.',
            count($ids),
            $ms,
            $conMovimiento,
        ));

        return Command::SUCCESS;
    }
}
