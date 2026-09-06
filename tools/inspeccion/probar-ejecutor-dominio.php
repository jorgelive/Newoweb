<?php
// Sonda: PHP → Node → dominio/, con una cotización real. No escribe nada.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
$c = $kernel->getContainer();

$ejecutor = new App\Dominio\EjecutorDeDominio(
    dirname(__DIR__, 2),
    $_SERVER['DOMINIO_NODE_BINARIO'] ?? null,
    new Psr\Log\NullLogger(),
);
$op = new App\Cotizacion\Dominio\ComponerItinerario();

$fixture = json_decode(file_get_contents(dirname(__DIR__, 2).'/dominio/cotizacion/__fixtures__/2KVBMX.json'), true);

$t = microtime(true);
$dias = $ejecutor->ejecutarUna($op, $fixture);
printf("una entrada  → %d días en %d ms\n", count($dias), (int)((microtime(true)-$t)*1000));

$t = microtime(true);
$lote = $ejecutor->ejecutar($op, [$fixture, $fixture, $fixture]);
printf("lote de tres → %s días en %d ms  (una sola invocación)\n",
    implode('/', array_map('count', $lote)), (int)((microtime(true)-$t)*1000));

// Y que un contrato equivocado REVIENTE en vez de devolver algo a medias.
$roto = new class implements App\Dominio\Contrato\OperacionDominioInterface {
    public function puntoDeEntrada(): string { return 'cotizacion/itinerario.cli.ts'; }
    public function contrato(): string { return 'itinerario@99'; }
};
try {
    $ejecutor->ejecutarUna($roto, $fixture);
    echo "❌ el contrato equivocado NO reventó\n";
} catch (App\Dominio\Excepcion\DominioNoDisponible $e) {
    printf("contrato roto → revienta ✓ (%s…)\n", substr($e->getMessage(), 0, 72));
}

// ── El camino real: entidad → normalizer → ejecutor ──────────────────────────
echo "\n--- camino real ---\n";
$em = $c->get('doctrine')->getManager();
$file = $em->getRepository(App\Cotizacion\Entity\CotizacionFile::class)->findOneBy(['localizador' => '2KVBMX']);
$cot = $em->getRepository(App\Cotizacion\Entity\Cotizacion::class)->findOneBy(['file' => $file, 'version' => 2]);
printf("visible: %s\n", $cot?->esVisibleParaCliente() ? 'sí' : 'NO');

try {
    $norm = $c->get('test.service_container')?->get('serializer') ?? null;
} catch (Throwable) { $norm = null; }

$refl = new ReflectionMethod(App\Dominio\EjecutorDeDominio::class, 'ejecutar');
try {
    $svc = new App\Cotizacion\Service\ItinerarioCompuesto($ejecutor, $op, $c->get('app.serializer.publico'));
    printf("días: %d\n", count($svc->dias($cot)));
} catch (Throwable $e) {
    printf("FALLA: %s\n  → %s\n", get_class($e), substr($e->getMessage(), 0, 300));
}
