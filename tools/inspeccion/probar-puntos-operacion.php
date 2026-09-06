<?php
// Sonda local (gitignored): resuelve los puntos de servicios reales de La Biblia.
require __DIR__ . '/../../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/../../.env');
$k = new App\Kernel('dev', true); $k->boot();
$em = $k->getContainer()->get('doctrine')->getManager();

$resolver = new App\Operacion\Service\OperacionPuntosDelServicio(
    new App\Cotizacion\Service\CotizacionPuntosDelServicio($em),
    new App\Cotizacion\Service\CadenaDeAlojamientoBuilder($em),
);

$c = $em->getConnection();
$fileId = $c->fetchOne('SELECT LOWER(HEX(file_id)) FROM operacion_servicio GROUP BY file_id ORDER BY COUNT(*) DESC LIMIT 1');
$uuid = substr($fileId,0,8).'-'.substr($fileId,8,4).'-'.substr($fileId,12,4).'-'.substr($fileId,16,4).'-'.substr($fileId,20);

$servicios = $em->getRepository(App\Operacion\Entity\OperacionServicio::class)
    ->findBy(['file' => $uuid], ['fechaServicio' => 'ASC']);

printf("Expediente %s — %d servicios en La Biblia\n\n", $uuid, count($servicios));

$avisos = [];
foreach ($servicios as $s) {
    $p = $resolver->para($s);
    if (!$p->aplica) { continue; }
    printf("%-10s %-34s %-30s → %s\n",
        $s->getFechaServicio()?->format('d/m/Y') ?? '—',
        mb_substr($s->getDescripcionServicio(), 0, 33),
        mb_substr($p->recojo ?? '⚠ sin resolver', 0, 29),
        $p->tieneEntrega ? mb_substr($p->entrega ?? '⚠ sin resolver', 0, 40) : '(no entrega)');
    foreach ($p->avisos as $a) { $avisos[] = $a; }
}
if ($avisos !== []) {
    echo "\n══ Avisos ══\n";
    foreach (array_unique($avisos) as $a) { echo "  · $a\n"; }
}
