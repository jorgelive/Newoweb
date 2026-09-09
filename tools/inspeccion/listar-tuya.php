<?php

declare(strict_types=1);

/**
 * Lista los aparatos del proyecto Tuya y comprueba qué sabe hacer cada uno.
 *
 * Es el paso previo al alta: las capacidades (`mideConsumo`, `conmutable`) son DERIVADAS —las
 * reporta el aparato en `/specifications`— y `Version20260815020000` las hace nacer en `false`
 * justo para que nadie las teclee. Esto es lo que las averigua.
 *
 * Complementa a `probar-tuya.php`, que mira UN aparato que ya sabes cuál es. Éste contesta a la
 * pregunta de antes: cuáles hay.
 *
 *   TUYA_CLIENT_ID=xxx TUYA_SECRET=yyy php tools/inspeccion/listar-tuya.php
 *   … php tools/inspeccion/listar-tuya.php --energia          # además prueba statistics-trend
 *
 * ⚠️ `--energia` es la comprobación que de verdad importa antes de construir nada: el endpoint
 * horario es el que sostiene la facturación entera y depende de una suscripción (Power Management)
 * que puede estar en periodo de prueba. Si caduca, deja de responder y el módulo se queda sin
 * datos — sin error visible en ninguna otra parte.
 */

$clientId = getenv('TUYA_CLIENT_ID') ?: '';
$secret = getenv('TUYA_SECRET') ?: '';
$host = getenv('TUYA_HOST') ?: 'https://openapi.tuyaus.com';
$probarEnergia = in_array('--energia', $argv, true);

if ($clientId === '' || $secret === '') {
    fwrite(STDERR, "Uso: TUYA_CLIENT_ID=… TUYA_SECRET=… php tools/inspeccion/listar-tuya.php [--energia]\n");
    exit(1);
}

$firmar = static function (string $path, string $t, string $token) use ($clientId, $secret): string {
    // ⚠️ Parámetros ordenados alfabéticamente en la cadena a firmar: con dos o más, si el orden
    // no coincide con el de la URL, Tuya responde «sign invalid» sin explicar nada.
    $stringToSign = "GET\n" . hash('sha256', '') . "\n\n" . $path;

    return strtoupper(hash_hmac('sha256', $clientId . $token . $t . $stringToSign, $secret));
};

$llamar = static function (string $path, string $token = '') use ($host, $clientId, $firmar): array {
    if (str_contains($path, '?')) {
        [$ruta, $query] = explode('?', $path, 2);
        parse_str($query, $params);
        ksort($params);
        $path = $ruta . '?' . http_build_query($params);
    }

    $t = (string) (int) (microtime(true) * 1000);
    $cabeceras = [
        'client_id: ' . $clientId,
        'sign: ' . $firmar($path, $t, $token),
        't: ' . $t,
        'sign_method: HMAC-SHA256',
    ];

    if ($token !== '') {
        $cabeceras[] = 'access_token: ' . $token;
    }

    $ch = curl_init($host . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $cabeceras,
        CURLOPT_TIMEOUT => 20,
        // El endpoint cierra mal los streams HTTP/2 y curl aborta con PROTOCOL_ERROR.
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    return json_decode((string) $raw, true) ?: ['raw' => $raw];
};

$token = $llamar('/v1.0/token?grant_type=1');

if (($token['success'] ?? false) !== true) {
    echo "❌ Sin token:\n", json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}

$accessToken = $token['result']['access_token'];
echo "✅ Token obtenido\n\n";

// ── Los aparatos ──────────────────────────────────────────────────────────
// Se prueban dos rutas porque cuál está disponible depende de qué APIs tenga suscritas el
// proyecto, y el error de la que falta no lo dice de forma evidente.
$rutas = [
    '/v1.0/iot-01/associated-users/devices?size=100',
    '/v2.0/cloud/thing/device?page_size=100',
];

$aparatos = [];
foreach ($rutas as $ruta) {
    $r = $llamar($ruta, $accessToken);

    if (($r['success'] ?? false) === true) {
        $aparatos = $r['result']['devices'] ?? $r['result'] ?? [];
        echo "Vía: {$ruta}\n\n";
        break;
    }

    echo "· {$ruta} → " . ($r['msg'] ?? 'sin respuesta') . "\n";
}

if ($aparatos === []) {
    echo "\n⚠️ Ningún aparato listado. Si las dos rutas fallan, el proyecto no tiene suscrita la API\n";
    echo "   de gestión de dispositivos, o la cuenta de la app no está vinculada al proyecto.\n";
    exit(1);
}

printf("%d aparato(s)\n\n", count($aparatos));

foreach ($aparatos as $a) {
    $id = $a['id'] ?? $a['device_id'] ?? '?';

    printf(
        "── %s\n   id %s · producto %s · %s\n",
        $a['name'] ?? '(sin nombre)',
        $id,
        $a['product_id'] ?? '?',
        ($a['online'] ?? false) ? 'EN LÍNEA' : 'desconectado'
    );

    // Las capacidades, que es a lo que viene esto: si expone add_ele mide, si expone switch_1
    // conmuta. Es exactamente lo que el alta tiene que copiar, y por eso no se teclea.
    $spec = $llamar('/v1.0/devices/' . $id . '/specifications', $accessToken);
    $codigos = array_column($spec['result']['status'] ?? [], 'code');

    printf(
        "   mide: %s · conmuta: %s\n   dp: %s\n\n",
        in_array('add_ele', $codigos, true) ? 'SÍ' : 'no',
        in_array('switch_1', $codigos, true) ? 'SÍ' : 'no',
        $codigos === [] ? '(sin especificación)' : implode(', ', $codigos)
    );
}

// ── El endpoint horario, que es el que sostiene la facturación ────────────
if (!$probarEnergia) {
    echo "Para comprobar el endpoint horario de consumo: --energia\n";
    exit(0);
}

$conContometro = [];
foreach ($aparatos as $a) {
    $id = $a['id'] ?? $a['device_id'] ?? '';
    $spec = $llamar('/v1.0/devices/' . $id . '/specifications', $accessToken);

    if (in_array('add_ele', array_column($spec['result']['status'] ?? [], 'code'), true)) {
        $conContometro[] = $id;
    }
}

echo "── ENERGÍA POR HORA ──────────────────────────────────────────────\n";

foreach ($conContometro as $id) {
    // Máximo 24 h por llamada en granularidad horaria.
    $fin = new DateTimeImmutable('now');
    $ini = $fin->modify('-23 hours');

    $r = $llamar(sprintf(
        '/v1.0/iot-03/energy/electricity/devices/statistics-trend?device_id=%s&energy_action=consume&energy_type=electricity&end_time=%s&start_time=%s&statistics_type=hour',
        $id,
        $fin->format('YmdH'),
        $ini->format('YmdH')
    ), $accessToken);

    if (($r['success'] ?? false) !== true) {
        printf("%s → ❌ %s (código %s)\n", $id, $r['msg'] ?? '?', (string) ($r['code'] ?? '?'));
        continue;
    }

    $cubos = $r['result'] ?? [];
    printf("%s → ✅ %d cubo(s)\n", $id, is_countable($cubos) ? count($cubos) : 0);
    echo json_encode($cubos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
}
