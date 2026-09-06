<?php
// Las dos cosas que tenían que ser ciertas para que el botón «Editar» vuelva a funcionar:
//   1. El controlador devuelve `Response` — era el 500 (`ControllerDoesNotReturnResponse`).
//   2. El cuerpo trae `@id` e `id`, que es lo que leen los dos consumidores del front.
//
// No se levanta el firewall: eso probaría el login, no esto.
require '/Users/jorgegomez/Sites/Newoweb/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/Users/jorgegomez/Sites/Newoweb/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();

use App\Message\Controller\Api\ConversacionPorAsuntoController;
use App\Message\Entity\MessageConversation;
use Symfony\Component\HttpFoundation\Response;

$lineas = [];

$tipo = (string) (new ReflectionMethod(ConversacionPorAsuntoController::class, '__invoke'))->getReturnType();
$lineas[] = ($tipo === Response::class ? '✅' : '❌')
    . sprintf(' __invoke(): %s  — devolver la entidad era el 500', $tipo);

// El normalizador se saca del PROPIO controlador —que sí es público, por `#[AsController]`—
// en vez de pedirlo al contenedor, que lo tiene privado. De paso comprueba que el servicio
// está bien cableado con su dependencia nueva.
$controlador = $kernel->getContainer()->get(ConversacionPorAsuntoController::class);
$normalizador = (new ReflectionProperty($controlador, 'normalizador'))->getValue($controlador);
$lineas[] = '✅ el controlador se instancia con su normalizador inyectado';
$hilo = $em->getRepository(MessageConversation::class)->findOneBy([]);

if ($hilo === null) {
    $lineas[] = '⚠️  No hay conversaciones en local: el cuerpo no se puede comprobar.';
} else {
    $cuerpo = $normalizador->normalize($hilo, 'jsonld', ['groups' => ['conversation:read']]);

    $lineas[] = (isset($cuerpo['@id']) ? '✅' : '❌') . ' el cuerpo trae `@id` (lo mira chatStore)';
    $lineas[] = (isset($cuerpo['id']) ? '✅' : '❌') . ' el cuerpo trae `id` (lo lee reservasStore)';
    $lineas[] = sprintf('   claves: %s…', implode(', ', array_slice(array_keys($cuerpo), 0, 8)));
}

echo "\n\n" . implode("\n", $lineas) . "\n";
