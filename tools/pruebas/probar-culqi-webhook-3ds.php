<?php

declare(strict_types=1);

/**
 * Sondeo de SÓLO LECTURA: las dos piezas nuevas contra datos reales de producción.
 *
 *  1. `CulqiWebhookController::datosDelEvento()` sobre el payload literal de un aviso de Culqi
 *     —el que lleva `data` como CADENA— y sobre la forma de objeto, por si algún día cambia.
 *  2. `CulqiRechazoException::pideAutenticacion3DS()` sobre las tres señales medidas.
 *
 * No abre base de datos ni toca nada: sólo llama a las funciones. Se ejecuta con
 *   php var/probar-culqi-webhook-3ds.php <ruta-al-payload.json>
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Finanzas\Controller\Webhook\CulqiWebhookController;
use App\Finanzas\Service\Culqi\CulqiRechazoException;

$ruta = $argv[1] ?? null;

if ($ruta === null || !is_readable($ruta)) {
    fwrite(STDERR, "Uso: php var/probar-culqi-webhook-3ds.php <payload.json>\n");
    exit(1);
}

$metodo = new ReflectionMethod(CulqiWebhookController::class, 'datosDelEvento');
$idDeCargo = new ReflectionMethod(CulqiWebhookController::class, 'idDeCargo');
$controlador = (new ReflectionClass(CulqiWebhookController::class))->newInstanceWithoutConstructor();

/** @var array<string, mixed> $payload */
$payload = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);

echo "── 1. El aviso real de Culqi ────────────────────────────────────\n";
echo '   `data` llega como: ' . get_debug_type($payload['data'] ?? null) . "\n";

$payload['data'] = $metodo->invoke(null, $payload);

$id = $idDeCargo->invoke($controlador, $payload);
$enlaceId = $payload['data']['metadata']['enlaceId'] ?? null;

printf("   id del cargo   : %s\n", $id ?? '(ninguno) ❌');
printf("   enlace del meta: %s\n", is_string($enlaceId) ? $enlaceId : '(ninguno) ❌');
printf("   veredicto      : %s\n\n", $id !== null && is_string($enlaceId)
    ? '✅ el aviso se puede procesar'
    : '❌ se ignoraría, como los cuatro que llegaron');

echo "── 2. La forma de objeto, por si Culqi la cambia ────────────────\n";
$comoObjeto = ['data' => ['id' => 'chr_live_prueba', 'metadata' => ['enlaceId' => 'x']]];
$plano = $metodo->invoke(null, $comoObjeto);
printf("   objeto  → id %s\n", $plano['id'] ?? '❌');
printf("   basura  → %s\n", json_encode($metodo->invoke(null, ['data' => 'no soy json'])));
printf("   ausente → %s\n\n", json_encode($metodo->invoke(null, [])));

echo "── 3. Las tres señales del reto 3DS ─────────────────────────────\n";
$casos = [
    'POST 200 con action_code REVIEW' => ['object' => 'authentication', 'action_code' => 'REVIEW'],
    'cargo denegado (decline_code)' => ['object' => 'charge', 'outcome' => ['decline_code' => 'authentication_required']],
    'código medido DNGE0116' => ['object' => 'charge', 'outcome' => ['type' => 'operacion_denegada', 'code' => 'DNGE0116']],
    'fondos insuficientes (NO es 3DS)' => ['object' => 'error', 'code' => 'DNGE0002'],
    'cargo autorizado (NO es 3DS)' => ['object' => 'charge', 'outcome' => ['type' => 'venta_exitosa', 'code' => 'AUT0000']],
];

foreach ($casos as $titulo => $cuerpo) {
    $excepcion = new CulqiRechazoException('prueba', $cuerpo);
    printf("   %-34s → %s\n", $titulo, $excepcion->pideAutenticacion3DS() ? 'reto 3DS' : 'no');
}
