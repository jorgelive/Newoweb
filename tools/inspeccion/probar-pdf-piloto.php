<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();

$req = Symfony\Component\HttpFoundation\Request::create(
    'https://pax.openperu.test/client/cotizacion/2KVBMX/2/itinerario.pdf'
);

try {
    // catch=false → la excepción sube en vez de convertirse en respuesta.
    $res = $kernel->handle($req, Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, false);
    printf("HTTP %d · %d bytes\n", $res->getStatusCode(), strlen($res->getContent()));
    file_put_contents($argv[1] ?? '/tmp/piloto.pdf', $res->getContent());
} catch (Throwable $e) {
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        printf("%s\n  %s\n", get_class($x), substr($x->getMessage(), 0, 420));
    }
}
