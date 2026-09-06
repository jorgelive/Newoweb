<?php

declare(strict_types=1);

/**
 * Prueba con DATOS REALES de `app:pms:corregir-pais-ota`, en transacción con ROLLBACK.
 *
 * No hay test unitario que cubra esto: el criterio depende de las reservas guardadas y de sus
 * teléfonos. Lo que se comprueba es lo que un unitario no puede decir —que la segunda pasada no
 * toca nada— y se deshace todo al final.
 *
 *   php var/probar-corregir-pais.php
 */

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

$app = new Application($kernel);
$app->setAutoExit(false);

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

$correr = static function (bool $seco) use ($app): string {
    $salida = new BufferedOutput();
    $app->run(new ArrayInput(array_filter([
        'command' => 'app:pms:corregir-pais-ota',
        '--dry-run' => $seco ?: null,
    ])), $salida);

    return $salida->fetch();
};

$cuantas = static function (string $texto): int {
    preg_match('/(\d+) reserva\(s\) (?:se corregirían|corregidas)/u', $texto, $m);

    return (int) ($m[1] ?? -1);
};

$conn->beginTransaction();

try {
    $antes = $cuantas($correr(seco: true));
    echo "1. En seco, antes de nada: {$antes} reservas a corregir\n";

    $aplicadas = $cuantas($correr(seco: false));
    echo "2. Aplicado de verdad:     {$aplicadas} reservas corregidas\n";

    $em->clear();

    $segunda = $cuantas($correr(seco: true));
    echo "3. Segunda pasada en seco: {$segunda} reservas a corregir\n\n";

    // ⚠️ `$antes === 0` NO es un fallo: es que ya no queda nada que corregir, o sea el estado
    // sano. La versión anterior exigía `$antes > 0` y por eso salía en ❌ justo cuando el
    // comando ya había hecho su trabajo — una prueba que se pone roja cuando todo está bien.
    if ($antes === 0) {
        echo "✅ NADA QUE CORREGIR: no queda ninguna reserva con el país mal.\n";
        echo "   (la idempotencia no se puede ejercitar sin casos; no es un fallo)\n";
        $ok = true;
    } else {
        $ok = $antes === $aplicadas && $segunda === 0;
        echo $ok
            ? "✅ IDEMPOTENTE: la segunda pasada no toca nada.\n"
            : "❌ ALGO NO CUADRA: revisa el criterio.\n";
    }
} finally {
    $conn->rollBack();
    echo "↩️  rollback hecho: la base queda como estaba.\n";
}
