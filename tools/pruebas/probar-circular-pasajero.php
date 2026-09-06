<?php
// ¿Qué devuelve guardar un pasajero que pertenece a un subgrupo, y qué devuelve guardar el grupo?
// El pasajero lleva sus pertenencias, cada pertenencia su grupo, y el grupo conoce a sus
// miembros… que incluyen esa misma pertenencia. Si la respuesta se serializa sin grupos de
// normalización, el serializador da vueltas y corta con una CircularReferenceException — 500 al
// guardar. Sin base de datos: el grafo se arma a mano y el contexto se lee del propio #[ApiResource].
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel('test', false);
$kernel->boot();
$serializer = $kernel->getContainer()->get('test.service_container')->get('serializer');

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Enum\DocumentoTipoEnum;

$pasajero = (new CotizacionFilepasajero())->setNombre('Daron Isai')->setApellido('Ascarsa Vivanco');
$grupo = (new CotizacionFileGrupo())->setClave('BBBBB');
$pertenencia = (new CotizacionPasajeroGrupo())->setGrupo($grupo);
$pasajero->addPertenencia($pertenencia);
$grupo->addMiembro($pertenencia);   // el otro lado, que es el que cierra el círculo

// Y el expediente colgando, que es lo que hacía pesar 1,13 MB la respuesta de un guardado.
$expediente = (new CotizacionFile())->setNombreGrupo('Punta Cana 2026');
$pasajero->setFile($expediente);
$grupo->setFile($expediente);
$pasajero->addIdentificacion(
    (new CotizacionPasajeroIdentificacion())->setTipo(DocumentoTipoEnum::DNI)->setNumero('73924317')
);

/** El contexto REAL de cada operación de escritura, leído del atributo. */
$operacionesDeEscritura = static function (string $clase): array {
    $atributo = (new ReflectionClass($clase))->getAttributes(ApiResource::class)[0]->newInstance();
    $ops = [];
    foreach ($atributo->getOperations() ?? [] as $op) {
        if ($op instanceof HttpOperation && in_array($op->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $ops[$op->getMethod()] = $op->getNormalizationContext() ?? [];
        }
    }

    return $ops;
};

$fallos = 0;
foreach ([$pasajero::class => $pasajero, $grupo::class => $grupo] as $clase => $objeto) {
    foreach ($operacionesDeEscritura($clase) as $metodo => $contexto) {
        $etiqueta = sprintf('%-28s %-6s %s', (new ReflectionClass($clase))->getShortName(), $metodo, $contexto === [] ? '(SIN grupos)' : implode(',', $contexto['groups'] ?? []));
        try {
            $json = $serializer->serialize($objeto, 'jsonld', $contexto + ['resource_class' => $clase]);
            printf("✅ %s → %d bytes\n", $etiqueta, strlen($json));
            if ($metodo === 'PATCH') {
                printf("   claves: %s\n", implode(', ', array_keys(json_decode($json, true))));
                printf("   ¿arrastra el expediente? %s\n", str_contains($json, 'Punta Cana') ? '⚠️ SÍ' : 'no');
            }
        } catch (Throwable $e) {
            ++$fallos;
            printf("💥 %s\n   %s: %s\n", $etiqueta, $e::class, explode("\n", $e->getMessage())[0]);
        }
    }
}

printf("\n%s\n", $fallos === 0 ? '✅ Ninguna respuesta de escritura entra en bucle.' : "⚠️ $fallos operación(es) devolverían 500.");
