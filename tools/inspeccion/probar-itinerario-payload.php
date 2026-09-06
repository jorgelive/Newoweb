<?php
// Vuelca el payload público de una cotización tal como lo recibe `pax`, para alimentar el módulo
// de dominio desde Node. No escribe nada.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();

$req = Symfony\Component\HttpFoundation\Request::create(sprintf(
    'http://%s/platform/sales/client/cotizacion/cotizacion_file/%s/%d',
    $_SERVER['APP_HOST_API'] ?? 'newapi.openperu.test', $argv[1] ?? '2KVBMX', (int)($argv[2] ?? 2)
));
$req->headers->set('Accept', 'application/json');
$res = $kernel->handle($req);

fwrite(STDERR, sprintf("HTTP %d\n", $res->getStatusCode()));
echo $res->getContent();
