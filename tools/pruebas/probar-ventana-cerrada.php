<?php
// ¿Se puede mandar TEXTO LIBRE a una ventana de 24 h cerrada? (¿tenemos Direct Send?)
//
// ── Por qué NO pasa por nuestra cola ────────────────────────────────────────
// `WhatsappMetaSendEnqueuer` lanza antes de salir del servidor cuando la ventana está
// cerrada y no hay plantilla. Saltarse esa guarda exigiría abrir un camino en producción
// que después alguien reutiliza sin saber para qué era. Aquí se le pregunta a Meta
// DIRECTAMENTE con nuestras credenciales: misma respuesta, cero cambios en el pipeline.
//
// Lo que se mide es el código que devuelve Meta, no el contenido:
//   · entrega          → Direct Send activo para este WABA.
//   · error 131047     → sigue haciendo falta plantilla («more than 24 hours»).
//   · error 131026/otro→ el mensaje se leerá en la salida tal cual.
//
// ⚠️ MANDA UN MENSAJE DE VERDAD. Sólo a un número del equipo.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();

use App\Exchange\Entity\MetaConfig;
use Symfony\Component\HttpClient\HttpClient;

$destino = $argv[1] ?? null;

if ($destino === null) {
    exit("Uso: php var/probar-ventana-cerrada.php <numero_e164_sin_mas>\n"
       . "Ej.:  php var/probar-ventana-cerrada.php 51958191965\n");
}

$config = $em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);

if ($config === null) {
    exit("No hay MetaConfig activa.\n");
}

$phoneId = $config->getPhoneId();
$token   = $config->getApiKey();

if (!$phoneId || !$token) {
    exit("La MetaConfig activa no tiene phoneId o apiKey.\n");
}

// El cuerpo, con la forma del resumen que estamos preparando. El contenido da igual para
// lo que se mide; se usa el real para ver de paso cómo se ven los saltos de línea.
$texto = "Hola equipo,\n"
    . "Prueba técnica de ventana cerrada.\n\n"
    . "Total: 92.83 USD (S/ 315.00)\n\n"
    . "Se puede pagar de estas maneras:\n"
    . "· Efectivo (a tu llegada): 92.83 USD\n"
    . "· Tarjeta / Enlace (incluye 5.5%): 97.94 USD\n\n"
    . "Si lees esto, el texto libre salió fuera de la ventana de 24 h.";

$url = sprintf('%s/%s/messages', rtrim($config->getBaseUrl(), '/'), $phoneId);

printf("POST %s\ndestino: %s\n\n", $url, $destino);

// Cliente propio y no el del contenedor: `http_client` es privado. Aquí no hace falta el
// del framework — es una petición suelta con nuestras credenciales.
$http = HttpClient::create();

try {
    $res = $http->request('POST', $url, [
        'auth_bearer' => $token,
        'json' => [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $destino,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $texto],
        ],
        'timeout' => 20,
    ]);

    $codigo = $res->getStatusCode();
    $cuerpo = $res->toArray(false);

    printf("HTTP %d\n%s\n", $codigo, json_encode($cuerpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $error = $cuerpo['error'] ?? null;

    echo "\n";
    if ($codigo < 300 && $error === null) {
        echo "✅ ENTREGADO — texto libre fuera de ventana: Direct Send parece ACTIVO.\n";
    } else {
        printf("❌ RECHAZADO — code=%s subcode=%s\n   %s\n",
            $error['code'] ?? '?',
            $error['error_subcode'] ?? '—',
            $error['message'] ?? '(sin mensaje)',
        );
        echo "   131047 = sigue haciendo falta plantilla fuera de la ventana.\n";
    }
} catch (Throwable $e) {
    printf("LANZÓ %s: %s\n", $e::class, $e->getMessage());
}
