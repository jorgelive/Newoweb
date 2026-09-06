<?php
// Las guardas del reembolso, contra datos reales y en transacción con rollback.
//
// NO llama a Culqi: se comprueban las reglas que rodean al dinero, que son las que el
// revisor encontró rotas. La llamada a la pasarela se prueba con el cargo de 1.00 de test.
//
// Cubre los dos CRÍTICOS que se arreglaron:
//   C1 · el `reason` que viaja a Culqi es la constante, no el texto del operador.
//   C2 · un aviso de cobro sobre un enlace ya REEMBOLSADO no lo resucita NI pisa su
//        respuesta cruda (el archivo {cobro, devolucion} es la prueba de la devolución).
// Y las guardas de estado: no se anula lo devuelto, no se devuelve lo no cobrado.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$conn = $em->getConnection();
$conn->beginTransaction();

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Service\Culqi\CulqiClient;
use App\Finanzas\Service\FinEnlacePagoService;

$ok = static fn (bool $cond, string $texto): string => sprintf("%s %s\n", $cond ? '✅' : '❌', $texto);

// `FinEnlacePagoService` es privado y el contenedor no lo entrega. En vez de arrancar en
// `test` —que apunta a otra base (`dbname_suffix: _test`) y dejaría de probar datos reales—
// se saca del CONTROLADOR, que sí es público y lo lleva ya cableado dentro.
$controlador = $c->get(App\Finanzas\Controller\Api\FinEnlacePagoApiController::class);
$prop = new ReflectionProperty($controlador, 'servicio');
$servicio = $prop->getValue($controlador);

try {
    // ── C1 · el reason que se manda a Culqi ──────────────────────────────────
    //
    // Se comprueba sobre el payload, sin llamar a la API: `reason` tiene que ser uno de los
    // tres valores del enum de Culqi pase lo que pase por el parámetro.
    $refl = new ReflectionClass(CulqiClient::class);
    $fuente = file_get_contents($refl->getFileName());

    echo $ok(
        str_contains($fuente, "'reason' => self::MOTIVO_POR_DEFECTO"),
        'C1 · a Culqi se le manda la CONSTANTE como reason, no el texto del operador',
    );
    echo $ok(
        CulqiClient::MOTIVO_POR_DEFECTO === 'solicitud_comprador',
        'C1 · y esa constante es uno de los tres valores válidos del enum de Culqi',
    );

    // ── C2 · un aviso tardío no resucita ni destruye la prueba ───────────────
    // Se FABRICA uno en la transacción en vez de buscarlo: en local puede no haber ninguno
    // pagado, y el caso que hay que probar es precisamente el que casi nunca existe.
    $moneda = $em->getRepository(App\Entity\Maestro\MaestroMoneda::class)->find('USD')
        ?? $em->getRepository(App\Entity\Maestro\MaestroMoneda::class)->findOneBy([]);

    $enlace = (new FinEnlacePago())
        ->setToken('probar-' . bin2hex(random_bytes(8)))
        ->setPasarela(App\Finanzas\Enum\FinPasarela::CULQI)
        ->setMoneda($moneda)
        ->setMontoNeto('10.00')
        ->setRecargoPorcentaje('5.50')
        ->setMontoTotal('10.55')
        ->setConcepto('Prueba de guardas de reembolso')
        ->setEstado(FinEnlacePagoEstado::PAGADO)
        ->setTransaccionUuid('chr_prueba');
    $em->persist($enlace);
    $em->flush();

    if (false) {
        echo "";
    } else {
        $archivo = ['cobro' => ['marca' => 'ESTA-ES-LA-PRUEBA'], 'devolucion' => ['id' => 'ref_test']];
        $enlace->setEstado(FinEnlacePagoEstado::REEMBOLSADO)->setRespuestaPasarela($archivo);
        $em->flush();

        // Llega un `charge.update` tardío de Culqi sobre ese mismo enlace.
        $servicio->confirmarPago($enlace, ['object' => 'charge', 'id' => 'chr_tardio']);

        echo $ok(
            $enlace->getEstado() === FinEnlacePagoEstado::REEMBOLSADO,
            'C2 · el enlace REEMBOLSADO no vuelve a PAGADO por un aviso tardío',
        );
        echo $ok(
            ($enlace->getRespuestaPasarela()['cobro']['marca'] ?? null) === 'ESTA-ES-LA-PRUEBA',
            'C2 · y el archivo {cobro, devolucion} NO se pisa con la respuesta nueva',
        );

        // ── Guardas de estado ────────────────────────────────────────────────
        $anular = null;
        try {
            $servicio->anular($enlace);
        } catch (Throwable $e) {
            $anular = $e->getMessage();
        }
        echo $ok($anular !== null, 'I2 · anular() se niega sobre un enlace ya REEMBOLSADO');
        printf("     %s\n", $anular ?? '(no lanzó)');

        $doble = null;
        try {
            $servicio->reembolsar($enlace, 'segunda vez');
        } catch (Throwable $e) {
            $doble = $e->getMessage();
        }
        echo $ok($doble !== null, 'reembolsar() se niega sobre uno ya devuelto (no hay doble devolución)');
        printf("     %s\n", $doble ?? '(no lanzó)');
    }

    // ── No se devuelve lo que nunca se cobró ─────────────────────────────────
    $pendiente = $em->getRepository(FinEnlacePago::class)->findOneBy(['estado' => FinEnlacePagoEstado::PENDIENTE]);

    if ($pendiente !== null) {
        $noPagado = null;
        try {
            $servicio->reembolsar($pendiente, 'x');
        } catch (Throwable $e) {
            $noPagado = $e->getMessage();
        }
        echo $ok($noPagado !== null, 'reembolsar() se niega sobre un enlace que nunca se cobró');
        printf("     %s\n", $noPagado ?? '(no lanzó)');
    }
} finally {
    $conn->rollBack();
    echo "\n(rollback hecho: la base queda como estaba)\n";
}
