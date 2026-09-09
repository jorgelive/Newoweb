<?php

declare(strict_types=1);

/**
 * ¿Se entera la Orden de que La Biblia cambió por debajo?
 *
 * El caso real que lo motivó (08/09/2026): una sincronización cambió el prestador de «Tunupa
 * Cusco» a «Tunupa Valle» en una orden YA EMITIDA y no avisó nadie. `getDivergencias()` miraba una
 * lista de campos escogidos a mano —cuatro de los doce que imprime la línea— y el prestador no
 * estaba.
 *
 * Escribe de verdad y hace ROLLBACK.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Operacion\Entity\OperacionOrdenServicio;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();

$fallos = 0;

try {
    $orden = null;

    foreach ($em->getRepository(OperacionOrdenServicio::class)->findAll() as $candidata) {
        if (count($candidata->getItems()) > 0 && count($candidata->getOperacionServicios()) > 0) {
            $orden = $candidata;
            break;
        }
    }

    if ($orden === null) {
        echo "No hay ninguna orden con ítems y filas vivas para probar.\n";
        exit(1);
    }

    printf("Orden de prueba: %s\n\n", $orden->getNumeroOs());

    // 1. En reposo no debe decir nada de la línea.
    $antes = $orden->getDivergencias();
    $conLinea = static fn (array $a): array => array_values(array_filter(
        $a,
        static fn (string $x): bool => str_contains($x, 'ya no dice lo mismo')
    ));

    printf("· En reposo, avisos de línea: %d %s\n", count($conLinea($antes)), $conLinea($antes) === [] ? '✅' : '❌');
    $fallos += $conLinea($antes) === [] ? 0 : 1;

    // 2. Se cambia el prestador en La Biblia, que es EL caso que se escapaba.
    $servicio = $orden->getOperacionServicios()->first();
    $original = $servicio->getPrestadorOverrideNombre();
    $servicio->setPrestadorOverrideNombre('Tunupa Valle (prueba)');
    $em->flush();

    $despues = $conLinea($orden->getDivergencias());

    printf("· Tras cambiar el prestador:  %d %s\n", count($despues), $despues !== [] ? '✅' : '❌ NO LO CAZA');
    $fallos += $despues !== [] ? 0 : 1;

    foreach ($despues as $aviso) {
        echo "    → $aviso\n";
    }

    $servicio->setPrestadorOverrideNombre($original);
} finally {
    $em->getConnection()->rollBack();
    echo "\n(rollback: no se ha guardado nada)\n";
}

exit($fallos === 0 ? 0 : 1);
