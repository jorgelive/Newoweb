<?php

declare(strict_types=1);

/**
 * ¿Qué dice exactamente el aviso de cobro, en sus dos formas?
 *
 * ⚠️ NO envía nada, y es deliberado. Avisar de verdad haría sonar el WhatsApp de todo el que
 * tenga ROLE_CUSTOMER_SUPPORT y móvil (ver docs/Mensajeria.md §16.7). Lo que aquí se comprueba
 * es lo único que hay que revisar a ojo —el TEXTO y las VARIABLES—, llamando por reflexión a
 * los dos métodos que los componen. El envío ya lo tiene probado el escalado, que usa el mismo
 * AvisoAlEquipoService.
 *
 * Comprueba además las dos reglas que Meta impone y que revientan el envío si se incumplen:
 * ninguna variable vacía y ninguna con salto de línea.
 *
 *   php tools/pruebas/probar-aviso-cobro.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Entity\Maestro\MaestroMoneda;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Service\Aviso\FinAvisoDeCobro;
use Doctrine\ORM\EntityManagerInterface;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
// El servicio es privado en el contenedor y aquí sólo se van a llamar dos métodos que no
// tocan el envío, así que se construye SIN constructor y se le inyecta lo único que usan: el
// ParameterBag (para la URL). Así ni existe la posibilidad de que algo se mande.
$reflejo = new ReflectionClass(FinAvisoDeCobro::class);
$aviso = $reflejo->newInstanceWithoutConstructor();
$reflejo->getProperty('params')->setValue($aviso, $kernel->getContainer()->getParameterBag());

$usd = $em->getRepository(MaestroMoneda::class)->find('USD');

/** Un enlace de mentira, en memoria y sin persistir: nada que revertir. */
$fabricar = static function (?FinOrigenCobro $origen, ?string $ref, ?string $cliente, ?string $medio) use ($usd): FinEnlacePago {
    $e = new FinEnlacePago();
    $e->setMoneda($usd);
    $e->setMontoNeto('120.00');
    $e->setMontoTotal('126.60');
    $e->setConcepto('Adelanto de la primera noche');
    $e->setClienteNombre($cliente);
    $e->setOrigenTipo($origen);
    $e->setOrigenReferencia($ref);
    $e->setMedioDetalle($medio);

    return $e;
};

$leer = static function (FinAvisoDeCobro $servicio, string $metodo, FinEnlacePago $enlace): mixed {
    $m = new ReflectionMethod($servicio, $metodo);

    return $m->invoke($servicio, $enlace);
};

$casos = [
    'reserva de alojamiento' => $fabricar(FinOrigenCobro::PMS_RESERVA, 'V7JHEZ', 'Desiree Egg', 'Visa ****4242'),
    'venta suelta, sin origen' => $fabricar(null, null, 'Marco Túllio', null),
    'sin nombre de cliente' => $fabricar(FinOrigenCobro::COTIZACION, 'COT-114', null, null),
];

$fallos = 0;

foreach ($casos as $titulo => $enlace) {
    printf("\n─── %s ───────────────────────────\n", $titulo);

    echo "DENTRO de ventana (texto libre):\n";
    echo $leer($aviso, 'redactar', $enlace) . "\n\n";

    /** @var array<string, string> $vars */
    $vars = $leer($aviso, 'variables', $enlace);

    echo "FUERA de ventana (variables de la plantilla):\n";

    foreach ($vars as $clave => $valor) {
        printf("  %-15s = %s\n", $clave, $valor);

        if (trim($valor) === '') {
            printf("  ❌ «%s» llega VACÍA: WhatsappMetaSendMappingStrategy lanzaría.\n", $clave);
            ++$fallos;
        }

        if (str_contains($valor, "\n")) {
            printf("  ❌ «%s» tiene salto de línea: Meta lo rechaza.\n", $clave);
            ++$fallos;
        }
    }
}

printf("\n%s\n", $fallos === 0
    ? '✅ Ninguna variable vacía ni multilínea: la plantilla puede hidratarse.'
    : "❌ $fallos problema(s).");

exit($fallos === 0 ? 0 : 1);
