<?php

declare(strict_types=1);

/**
 * El desglose canónico de las reservas con cargos negativos.
 *
 * `GenerarMensajePrepagoSkill::desgloseCargos()` reimplementaba las reglas del desglose y
 * descartaba los importes ≤ 0, así que un «Descuento tipo de cambio» de −0.20 hacía que el
 * mensaje dijera 66.17 y `consultar_cuenta` 65.97 — con el prepago del MISMO mensaje ya
 * calculado sobre 65.97, porque `PmsPrepagoCalculador::base()` sí usa el canónico.
 *
 * Desde que la skill llama a `getDesglosePorTipo()` la igualdad es por construcción. Lo que
 * comprueba esto es el DATO: que existen cargos negativos y cuánto movían.
 *
 * Read-only.  php tools/inspeccion/probar-desglose-prepago.php
 */

use App\Kernel;
use App\Pms\Entity\PmsReserva;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../../.env');

$kernel = new Kernel('dev', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

$conCargosNegativos = 0;
$desviacionTotal = 0.0;

foreach ($em->getRepository(PmsReserva::class)->findBy([], null, 500) as $reserva) {
    $info = $reserva->getInformacionFinanciera();
    if ($info === null || $info->getCargos()->isEmpty()) {
        continue;
    }

    $negativos = 0.0;
    foreach ($info->getCargos() as $cargo) {
        if (!$cargo->esCargo()) {
            continue;
        }
        $monto = (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0');
        if ($monto < 0) {
            $negativos += $monto;
        }
    }

    if ($negativos >= -0.0001) {
        continue;
    }

    $conCargosNegativos++;
    $desviacionTotal += abs($negativos);
    $canonico = array_sum(array_map('floatval', $info->getDesglosePorTipo()));

    printf(
        "%-8s  cuenta %8.2f   el mensaje decía %8.2f   (se comía %.2f)\n",
        (string) $reserva->getLocalizador(),
        $canonico,
        $canonico - $negativos,
        $negativos
    );
}

printf("\nReservas con cargos negativos: %d — desviación acumulada: %.2f\n", $conCargosNegativos, $desviacionTotal);
echo $conCargosNegativos === 0
    ? "Ninguna en esta base, pero la regla queda cerrada.\n"
    : "Ésas son las que el mensaje inflaba. Ahora salen por el desglose canónico.\n";
