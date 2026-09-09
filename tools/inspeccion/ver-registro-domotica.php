<?php

declare(strict_types=1);

/**
 * Enseña QUÉ se guarda de un aparato: con contómetro y sin él.
 *
 * Escribe una suscripción y tres lecturas de verdad —con sus listeners y sus tipos— y hace
 * ROLLBACK. Nada queda. Sirve para ver la forma real de la bitácora sin esperar a que un huésped
 * encienda una estufa.
 *
 *   php tools/inspeccion/ver-registro-domotica.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Domotica\Entity\DomoticaDispositivo;
use App\Domotica\Entity\DomoticaCambioEstado;
use App\Domotica\Entity\DomoticaLectura;
use App\Domotica\Entity\DomoticaSuscripcion;
use App\Domotica\Enum\DomoticaMotivoLectura;
use App\Kernel;
use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$em->getConnection()->beginTransaction();

try {
    $repo = $em->getRepository(DomoticaDispositivo::class);

    $conContometro = $repo->findOneBy(['mideConsumo' => true, 'visibleParaHuesped' => true]);
    $soloOnOff = $repo->findOneBy(['mideConsumo' => false, 'visibleParaHuesped' => true]);

    if ($conContometro === null || $soloOnOff === null) {
        echo "Faltan aparatos de ejemplo (uno que mida y uno que no, los dos visibles).\n";
        exit(1);
    }

    $evento = $em->getRepository(PmsEventoCalendario::class)->findOneBy([]);

    echo "═══ 1. APARATO CON CONTÓMETRO — «{$conContometro->getNombre()}» ═══\n\n";

    $suscripcion = new DomoticaSuscripcion();
    $suscripcion->setDispositivo($conContometro);
    $suscripcion->setEvento($evento);
    $suscripcion->setIniciaEn(new DateTimeImmutable('today 14:00'));
    $suscripcion->setTerminaEn(new DateTimeImmutable('+2 days 10:00'));
    $suscripcion->setLecturaInicial('546.000');
    $suscripcion->setTarifaSolesKwh('0.7900');
    $suscripcion->setCreditoDiarioSoles('10.00');
    $em->persist($suscripcion);

    // Tres horas seguidas: la estufa se enciende a las 15:00.
    $horas = [['2026090814', '546.000', '0.000'], ['2026090815', '549.100', '3.100'], ['2026090816', '551.400', '2.300']];
    $acumulado = 0.0;

    foreach ($horas as [$cubo, $total, $incremento]) {
        $acumulado += (float) $incremento;

        $l = new DomoticaLectura();
        $l->setDispositivo($conContometro);
        $l->setSuscripcion($suscripcion);
        $l->setLeidaEn(DateTimeImmutable::createFromFormat('YmdH', $cubo) ?: new DateTimeImmutable());
        $l->setCuboHorario($cubo);
        $l->setConsumoTotal($total);
        $l->setIncremento($incremento);
        $l->setConsumoCliente(number_format($acumulado, 3, '.', ''));
        $l->setMotivo(DomoticaMotivoLectura::Poll);
        $em->persist($l);
    }

    $suscripcion->setConsumoTotal('551.400');
    $suscripcion->setConsumoCliente(number_format($acumulado, 3, '.', ''));
    $em->flush();

    echo "domotica_lectura — una fila POR HORA:\n\n";
    printf("  %-12s %-14s %-16s %-12s %s\n", 'cubo', 'consumo_total', 'consumo_cliente', 'incremento', 'motivo');
    echo '  ' . str_repeat('─', 68) . "\n";

    foreach ($em->getRepository(DomoticaLectura::class)->findBy(['dispositivo' => $conContometro], ['cuboHorario' => 'ASC']) as $l) {
        printf("  %-12s %-14s %-16s %-12s %s\n", $l->getCuboHorario(), $l->getConsumoTotal(), $l->getConsumoCliente(), $l->getIncremento(), $l->getMotivo()->value);
    }

    echo "\n  Lo que LEE el huésped (DomoticaLectura::comoLinea()):\n\n";

    foreach ($em->getRepository(DomoticaLectura::class)->findBy(['dispositivo' => $conContometro], ['cuboHorario' => 'ASC']) as $l) {
        echo '    · ' . $l->comoLinea() . "\n";
    }

    printf(
        "\n  Y la cuenta:  %s kW·h × S/ %s = S/ %.2f  ·  crédito %d día(s) × S/ %s  →  A COBRAR S/ %.2f\n",
        $suscripcion->getConsumoCliente(),
        $suscripcion->getTarifaSolesKwh(),
        $suscripcion->consumoClienteSoles(),
        $suscripcion->diasDeCredito(),
        $suscripcion->getCreditoDiarioSoles(),
        $suscripcion->importeACobrarSoles()
    );

    echo "\n\n═══ 2. APARATO SÓLO ON/OFF — «{$soloOnOff->getNombre()}» ═══\n\n";

    $filas = $em->getRepository(DomoticaLectura::class)->findBy(['dispositivo' => $soloOnOff]);

    printf("  filas en domotica_lectura: %d\n", count($filas));
    printf("  suscripciones:             %d\n\n", count($em->getRepository(DomoticaSuscripcion::class)->findBy(['dispositivo' => $soloOnOff])));

    echo "  Todo lo que se sabe de él vive en su PROPIA fila, y es sólo el AHORA:\n\n";
    printf("    encendido        %s\n", var_export($soloOnOff->getEncendido(), true));
    printf("    estadoTomadoEn   %s\n", $soloOnOff->getEstadoTomadoEn()?->format('Y-m-d H:i:s') ?? '—');
    printf("    enLinea          %s\n", var_export($soloOnOff->isEnLinea(), true));
    printf("    potenciaVatios   %s\n", var_export($soloOnOff->getPotenciaVatios(), true));

    echo "\n  Y su historial vive en domotica_cambio_estado — sólo transiciones:\n\n";

    $repoCambios = $em->getRepository(DomoticaCambioEstado::class);
    $cambios = $repoCambios->historial($soloOnOff, new DateTimeImmutable('-7 days'), new DateTimeImmutable());

    if ($cambios === []) {
        echo "    (todavía ninguno: se escribe sólo cuando el interruptor CAMBIA)\n";
    } else {
        foreach ($cambios as $cambio) {
            printf("    · %-22s %s\n", $cambio->comoLinea(), $cambio->isHoraExacta() ? '' : '(hora del muestreo, no del aparato)');
        }
    }

    printf(
        "\n  Encendido en los últimos 7 días: %d minuto(s).\n",
        $repoCambios->minutosEncendido($soloOnOff, new DateTimeImmutable('-7 days'), new DateTimeImmutable())
    );

    echo "\n  ⚠️ Eso NO se factura: horas × vatios nominales no cuadra con ningún contador.\n";
    echo "     Sirve para informar, para operar, y para decidir dónde comprar contómetro.\n";
} finally {
    $em->getConnection()->rollBack();
    echo "\n(rollback: no se ha guardado nada)\n";
}
