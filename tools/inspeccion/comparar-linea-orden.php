<?php

declare(strict_types=1);

/** Enseña, lado a lado, la línea congelada y la que saldría hoy. Para ver QUÉ difiere. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$orden = $em->getRepository(OperacionOrdenServicio::class)->findOneBy(['numeroOs' => $argv[1] ?? 'OS-20260826-166']);

foreach ($orden->getItems() as $item) {
    $servicio = null;

    foreach ($orden->getOperacionServicios() as $s) {
        if ((string) $s->getId() === $item->getOperacionServicioId()) {
            $servicio = $s;
            break;
        }
    }

    if ($servicio === null) {
        continue;
    }

    $congelada = $item->lineaParaProveedor();
    $viva = OperacionOrdenServicioItem::desdeServicio($servicio)->lineaParaProveedor();

    if ($congelada === $viva) {
        continue;
    }

    echo "── ", mb_substr((string) $item->getNombreComponente(), 0, 40), "\n";
    echo "  congelada: ", str_replace("\n", ' ⏎ ', $congelada), "\n";
    echo "  viva:      ", str_replace("\n", ' ⏎ ', $viva), "\n\n";
    break;
}
