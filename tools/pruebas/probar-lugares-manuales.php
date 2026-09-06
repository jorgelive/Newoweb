<?php
// Ubicaciones propias de un componente MANUAL: que se guarden, que se normalicen, y que NO
// convivan con un maestro escríbase en el orden que se escriba. Transacción con rollback.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();
$conn->beginTransaction();

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Travel\Entity\TravelLugar;

$ok = static fn (string $q, bool $b): string => sprintf("%s %s\n", $b ? '✅' : '❌', $q);

try {
    $comp = $em->getRepository(CotizacionCotcomponente::class)->findOneBy([]);
    $lugares = $em->getRepository(TravelLugar::class)->findBy([], null, 2);

    if (!$comp || count($lugares) < 2) {
        echo "Sin datos con los que probar (hacen falta un componente y dos lugares).\n";
        $conn->rollBack();
        exit(0);
    }

    [$a, $b] = array_map(static fn (TravelLugar $l): string => (string) $l->getId(), $lugares);
    printf("Componente %s · lugares %s, %s\n\n", $comp->getId(), $a, $b);

    // 1. Manual: se guardan y sobreviven al flush.
    $comp->setComponenteMaestroId(null);
    $comp->setLugaresManuales([strtoupper($a), $b, $b, 'no-es-un-uuid']);
    $em->flush();
    $em->refresh($comp);

    echo $ok('se guardan', $comp->getLugaresManuales() !== []);
    echo $ok('normaliza a minúsculas', in_array(strtolower($a), $comp->getLugaresManuales(), true));
    echo $ok('quita duplicados y basura', count($comp->getLugaresManuales()) === 2);

    // 2. Vincular un maestro las borra.
    $comp->setComponenteMaestroId('11111111-1111-1111-1111-111111111111');
    $em->flush();
    $em->refresh($comp);
    echo $ok('vincular maestro las borra', $comp->getLugaresManuales() === []);

    // 3. Con maestro puesto no se dejan escribir — el orden del payload da igual.
    $comp->setLugaresManuales([$a]);
    echo $ok('con maestro no se escriben', $comp->getLugaresManuales() === []);

    // 4. Y al revés: escribir primero y vincular después acaba en el mismo sitio.
    $comp->setComponenteMaestroId(null);
    $comp->setLugaresManuales([$a]);
    $comp->setComponenteMaestroId('11111111-1111-1111-1111-111111111111');
    echo $ok('escribir y luego vincular: también vacío', $comp->getLugaresManuales() === []);
} finally {
    $conn->rollBack();
    echo "\n↩️  rollback\n";
}
