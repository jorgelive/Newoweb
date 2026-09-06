<?php
// Sonda: ¿el filtro ?ordenServicio=<iri> devuelve los servicios de la orden?
require dirname(__DIR__, 2).'/vendor/autoload.php';
$_SERVER['APP_ENV'] = 'dev'; $_SERVER['APP_DEBUG'] = '0';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new App\Kernel('dev', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

$ordenes = $em->getRepository(App\Operacion\Entity\OperacionOrdenServicio::class)->findAll();
foreach ($ordenes as $o) {
    printf("%s → %d servicios (relación Doctrine)\n", $o->getNumeroOs(), $o->getOperacionServicios()->count());
}
