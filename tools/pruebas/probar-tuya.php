<?php

declare(strict_types=1);

/**
 * Lee el estado crudo de un dispositivo Tuya. Sin integrar nada: solo mirar qué hay.
 *
 * Tuya firma cada petición con HMAC-SHA256, así que `curl` a pelo no vale: hay que calcular el
 * hash antes de llamar. Esto hace eso y nada más — pide el token, pide el estado, e imprime el
 * JSON tal cual llega, más una lectura de los campos de energía aplicando su escala.
 *
 * Las credenciales van por variables de entorno, no por argumentos: así no quedan en el historial
 * del shell.
 *
 *   TUYA_CLIENT_ID=xxx TUYA_SECRET=yyy php var/probar-tuya.php eb4776d865f5b3351angr3
 *
 * El endpoint por defecto es el de Western America, que es el data center que corresponde a las
 * cuentas registradas en Perú. Si el proyecto está en otra región, se cambia con TUYA_HOST.
 */

$clientId = getenv('TUYA_CLIENT_ID') ?: '';
$secret = getenv('TUYA_SECRET') ?: '';
$deviceId = $argv[1] ?? '';
$host = getenv('TUYA_HOST') ?: 'https://openapi.tuyaus.com';

if ($clientId === '' || $secret === '' || $deviceId === '') {
    fwrite(STDERR, "Uso: TUYA_CLIENT_ID=… TUYA_SECRET=… php var/probar-tuya.php <device_id>\n");
    exit(1);
}

/** SHA-256 del cuerpo. Con cuerpo vacío es siempre el mismo, pero se calcula igual. */
$sha256 = static fn (string $body): string => hash('sha256', $body);

/**
 * La firma de Tuya.
 *
 * El orden importa y no perdona: método, hash del cuerpo, cabeceras (vacío aquí) y ruta CON su
 * query. Y para las llamadas de negocio, el token va DENTRO de la cadena a firmar, entre el
 * client_id y el timestamp.
 */
$firmar = static function (
    string $method,
    string $path,
    string $body,
    string $t,
    string $token
) use ($clientId, $secret, $sha256): string {
    // ⚠️ LOS PARÁMETROS VAN ORDENADOS ALFABÉTICAMENTE en la cadena a firmar. La URL se llama con
    // el orden que sea, pero la firma se calcula sobre la versión ordenada — y si no coinciden,
    // Tuya responde «sign invalid» sin decir por qué. Con un solo parámetro no se nota; con dos,
    // falla siempre.
    if (str_contains($path, '?')) {
        [$ruta, $query] = explode('?', $path, 2);
        parse_str($query, $params);
        ksort($params);
        $path = $ruta . '?' . http_build_query($params);
    }

    $stringToSign = $method . "\n" . $sha256($body) . "\n" . "\n" . $path;
    $str = $clientId . $token . $t . $stringToSign;

    return strtoupper(hash_hmac('sha256', $str, $secret));
};

$llamar = static function (string $path, string $token = '') use ($host, $clientId, $firmar): array {
    // Se ordena aquí también: la URL que se llama tiene que ser la misma que se firmó.
    if (str_contains($path, '?')) {
        [$ruta, $query] = explode('?', $path, 2);
        parse_str($query, $params);
        ksort($params);
        $path = $ruta . '?' . http_build_query($params);
    }

    $t = (string) (int) (microtime(true) * 1000);

    $cabeceras = [
        'client_id: ' . $clientId,
        'sign: ' . $firmar('GET', $path, '', $t, $token),
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
        // Fuerza HTTP/1.1: el endpoint de Tuya cierra mal los streams HTTP/2 y curl aborta con
        // PROTOCOL_ERROR antes de leer el cuerpo.
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        fwrite(STDERR, 'Error de red: ' . curl_error($ch) . "\n");
        exit(1);
    }

    curl_close($ch);

    return json_decode((string) $raw, true) ?: ['raw' => $raw];
};

// ── 1. Token ──────────────────────────────────────────────────────────────
$token = $llamar('/v1.0/token?grant_type=1');

if (($token['success'] ?? false) !== true) {
    echo "❌ No se pudo obtener el token:\n";
    echo json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n\n";
    echo "Pistas habituales:\n";
    echo "  · 1106 / permission deny  → el data center no coincide (prueba TUYA_HOST=https://openapi.tuyaeu.com)\n";
    echo "  · 1004 / sign invalid     → client_id o secret mal copiados\n";
    echo "  · suscripción caducada    → renueva IoT Core en el proyecto\n";
    exit(1);
}

$accessToken = $token['result']['access_token'];
echo "✅ Token obtenido\n\n";

// ── 2. Estado del dispositivo, tal cual ───────────────────────────────────
$estado = $llamar('/v1.0/devices/' . $deviceId . '/status', $accessToken);

echo "── CRUDO ─────────────────────────────────────────────────────────\n";
echo json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n\n";

if (($estado['success'] ?? false) !== true) {
    exit(1);
}

// ── 3. Lo que importa, con su escala aplicada ─────────────────────────────
// Las escalas salen del «Standard Status Set» del Device Debugging: add_ele viene con scale 3,
// así que el entero se divide entre 1000 para leer kW·h.
$escalas = [
    'add_ele' => ['div' => 1000, 'unidad' => 'kW·h'],
    'cur_power' => ['div' => 10, 'unidad' => 'W'],
    'cur_current' => ['div' => 1, 'unidad' => 'mA'],
    'cur_voltage' => ['div' => 10, 'unidad' => 'V'],
];

echo "── LEÍDO ─────────────────────────────────────────────────────────\n";

foreach ($estado['result'] as $dp) {
    $code = (string) $dp['code'];
    $valor = $dp['value'];

    if (isset($escalas[$code]) && is_numeric($valor)) {
        printf(
            "%-14s %10s  →  %s %s\n",
            $code,
            (string) $valor,
            number_format($valor / $escalas[$code]['div'], 3),
            $escalas[$code]['unidad']
        );

        continue;
    }

    printf("%-14s %10s\n", $code, is_bool($valor) ? var_export($valor, true) : (string) $valor);
}

echo "\n⚠️  Compara `add_ele` con lo que enseña la app para ESE aparato:\n";
echo "   · si cuadra con el TOTAL de vida  → contador acumulado, la resta entrada−salida basta\n";
echo "   · si cuadra con el MES en curso   → contador mensual, hay que acumular incrementos\n";
