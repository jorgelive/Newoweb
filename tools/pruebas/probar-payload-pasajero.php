<?php
// ¿Qué hace la API con el payload que manda la ficha del pasajero cuando hay campos vacíos?
// El formulario usa '' para «vacío» y la API espera null: una fecha o un enum con cadena vacía no
// se pueden interpretar. Sin base de datos: sólo deserialización, con el contexto que declara el
// propio #[ApiResource].
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('test', false);
$kernel->boot();
$serializer = $kernel->getContainer()->get('test.service_container')->get('serializer');

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use App\Cotizacion\Entity\CotizacionFilepasajero;

$atributo = (new ReflectionClass(CotizacionFilepasajero::class))->getAttributes(ApiResource::class)[0]->newInstance();
$contexto = [];
foreach ($atributo->getOperations() ?? [] as $op) {
    if ($op instanceof HttpOperation && $op->getMethod() === 'PATCH') { $contexto = $op->getDenormalizationContext() ?? []; }
}
printf("Contexto de denormalización del PATCH: %s\n\n", json_encode($contexto));

$casos = [
    'como mandaba la ficha (Alma: sin fecha, sin sexo)' => [
        'nombre' => 'Alma Angelina', 'apellido' => 'Noriega Salazar',
        'sexo' => '', 'fechanacimiento' => '', 'tipo' => 'no_participa', 'telefono' => '', 'observaciones' => '',
        'identificaciones' => [['tipo' => 'DNI', 'numero' => '12345678', 'vencimiento' => '']],
    ],
    'como la manda ahora (vacíos como null)' => [
        'nombre' => 'Alma Angelina', 'apellido' => 'Noriega Salazar',
        'sexo' => null, 'fechanacimiento' => null, 'tipo' => 'no_participa', 'telefono' => null, 'observaciones' => null,
        'identificaciones' => [['tipo' => 'DNI', 'numero' => '12345678', 'vencimiento' => null]],
    ],
];

foreach ($casos as $titulo => $payload) {
    try {
        $serializer->denormalize($payload, CotizacionFilepasajero::class, 'jsonld', $contexto);
        printf("✅ %s → deserializa sin quejarse\n", $titulo);
    } catch (Symfony\Component\Serializer\Exception\PartialDenormalizationException $e) {
        $campos = array_map(static fn ($x) => $x->getPath(), $e->getErrors());
        printf("⚠️  %s → 422 con los campos: %s\n", $titulo, implode(', ', $campos));
    } catch (Throwable $e) {
        printf("💥 %s → %s (esto sale como 500)\n   %s\n", $titulo, $e::class, explode("\n", $e->getMessage())[0]);
    }
}
