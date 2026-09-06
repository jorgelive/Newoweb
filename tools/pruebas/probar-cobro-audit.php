<?php

declare(strict_types=1);

/**
 * Sondeo con ROLLBACK: que la fila de auditoría se escriba de verdad y se lea entera.
 *
 * No basta con que PHPStan y `schema:validate` estén contentos: un campo recién mapeado puede
 * quedar sin persistir si el ORM lo lee de una caché vieja, y entonces `flush()` corre sin
 * error y la columna se queda en NULL (ver CLAUDE.md). Esto lo comprueba escribiendo.
 *
 *   php tools/pruebas/probar-cobro-audit.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Finanzas\Entity\FinPasarelaCobroAudit;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Service\FinCobroAuditor;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(__DIR__ . '/../../.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$em->getConnection()->beginTransaction();

try {
    // El cargo denegado real, tal como llega: con datos del titular dentro.
    $cuerpo = [
        'object' => 'charge',
        'id' => 'chr_live_amECtx8jft1ti9zY',
        'amount' => 17568,
        'currency_code' => 'USD',
        'source' => ['cardNumber' => '447409******5298', 'email' => 'alguien@ejemplo.com'],
        'antifraud_details' => ['first_name' => 'Nombre'],
        'outcome' => [
            'type' => 'operacion_denegada',
            'code' => 'DNGE0116',
            'decline_code' => 'authentication_required',
            'merchant_message' => 'Denegación sospecha de fraude, se solicita autenticación 3DS',
        ],
    ];

    $audit = new FinPasarelaCobroAudit();
    $audit->setPasarela(FinPasarela::CULQI)->setCon3DS(false);
    $em->persist($audit);
    $em->flush();

    $senales = FinCobroAuditor::senalesDe($cuerpo);
    $audit
        ->setDesenlace(FinPasarelaCobroAudit::DESENLACE_RETO_3DS)
        ->setObjeto($senales['objeto'])
        ->setOutcomeType($senales['outcomeType'])
        ->setOutcomeCode($senales['outcomeCode'])
        ->setCargoId($senales['cargoId'])
        ->setMotivo($senales['motivo'])
        ->setRespuesta(FinCobroAuditor::sinDatosDelTitular($cuerpo));
    $em->flush();

    $id = $audit->getId();
    $em->clear();

    /** @var FinPasarelaCobroAudit|null $releido */
    $releido = $em->getRepository(FinPasarelaCobroAudit::class)->find($id);

    if ($releido === null) {
        echo "❌ la fila no se pudo releer\n";
        exit(1);
    }

    printf("desenlace   : %s\n", $releido->getDesenlace());
    printf("objeto      : %s\n", $releido->getObjeto() ?? '(null) ❌');
    printf("outcome     : %s / %s\n", $releido->getOutcomeType() ?? '❌', $releido->getOutcomeCode() ?? '❌');
    printf("cargo       : %s\n", $releido->getCargoId() ?? '❌');
    printf("con 3DS     : %s\n", $releido->isCon3DS() ? 'sí' : 'no');

    $respuesta = $releido->getRespuesta() ?? [];
    printf("respuesta   : %d claves, %s\n", count($respuesta),
        isset($respuesta['source']) || isset($respuesta['antifraud_details'])
            ? '❌ LLEVA DATOS DEL TITULAR'
            : '✅ sin datos del titular');
    printf("importe     : %s %s\n", $respuesta['amount'] ?? '❌', $respuesta['currency_code'] ?? '');
} finally {
    $em->getConnection()->rollBack();
    echo "\n↩️  rollback: no queda nada escrito.\n";
}
