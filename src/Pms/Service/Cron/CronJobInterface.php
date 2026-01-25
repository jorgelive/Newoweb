<?php

namespace App\Pms\Service\Cron;

use DateInterval;
use DateTimeImmutable;
use Symfony\Component\Console\Style\SymfonyStyle;

interface CronJobInterface
{
    /**
     * El nombre único del job (se usará en la BD como PK del cursor).
     * Ej: 'beds24_bookings_sync', 'beds24_rates_push'
     */
    public function getName(): string;

    /**
     * Define cuánto tiempo avanza el cursor en cada ejecución.
     * Ej: 'P1M' (1 mes) para reservas, 'P1W' (1 semana) para tarifas.
     */
    public function getStepInterval(): DateInterval;

    /**
     * Ejecuta la lógica del negocio.
     */
    public function execute(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        SymfonyStyle $io
    ): void;
}