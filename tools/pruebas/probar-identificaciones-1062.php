<?php
// ¿Guardar un pasajero que YA tiene documentos reescribe su fila, o intenta estrenar otra y choca
// con el índice único `(pasajero, tipo)`? Eso es lo que dio 500 en producción el 24/08/2026, y es
// justo la mitad que los tests unitarios no pueden cubrir: el orden en que Doctrine manda los
// INSERT y los DELETE dentro de un mismo flush.
//
//   php var/probar-identificaciones-1062.php              → con el arreglo (esperado: UPDATE)
//   php var/probar-identificaciones-1062.php --sin-arreglo → sin él (esperado: 1062, el bug)
//
// Transacción con rollback: no deja nada escrito.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$conn = $em->getConnection();
$conn->beginTransaction();

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\State\CotizacionFilepasajeroProcessor;

$conArreglo = !in_array('--sin-arreglo', $argv, true);

try {
    $pasajero = null;
    foreach ($em->getRepository(CotizacionFilepasajero::class)->findBy([], null, 2000) as $candidato) {
        // Uno que tenga las DOS colecciones pobladas: las dos tienen el mismo defecto y el mismo
        // tipo de índice único.
        if ($candidato->getIdentificaciones()->count() > 0 && $candidato->getPertenencias()->count() > 0) { $pasajero = $candidato; break; }
    }
    if ($pasajero === null) { throw new RuntimeException('No hay ningún pasajero con documentos y grupos en esta base.'); }

    printf("Pasajero: %s %s\n", $pasajero->getNombre(), $pasajero->getApellido());

    $antes = [];
    foreach ($pasajero->getIdentificaciones() as $doc) {
        $antes[$doc->getTipo()->value] = ['id' => (string) $doc->getId(), 'numero' => $doc->getNumero(), 'creado' => $doc->getCreatedAt()?->format('c')];
        printf("  antes  %-10s %-15s id=%s\n", $doc->getTipo()->value, $doc->getNumero(), $doc->getId());
    }

    $gruposAntes = [];
    foreach ($pasajero->getPertenencias() as $pertenencia) {
        $gruposAntes[(string) $pertenencia->getGrupo()->getId()] = (string) $pertenencia->getId();
        printf("  antes  grupo      %-15s id=%s\n", $pertenencia->getGrupo()->getEtiqueta(), $pertenencia->getId());
    }

    // Lo que deja el deserializador de API Platform con una lista mandada sin ids: fuera las
    // viejas, dentro objetos nuevos con los mismos tipos.
    foreach ($pasajero->getIdentificaciones()->toArray() as $viejo) {
        $pasajero->removeIdentificacion($viejo);
        $pasajero->addIdentificacion(
            (new CotizacionPasajeroIdentificacion())
                ->setTipo($viejo->getTipo())
                ->setNumero($viejo->getNumero().'X')
                ->setVencimiento($viejo->getVencimiento())
                ->setPaisEmisor($viejo->getPaisEmisor())
        );
    }

    foreach ($pasajero->getPertenencias()->toArray() as $vieja) {
        $pasajero->removePertenencia($vieja);
        $pasajero->addPertenencia((new CotizacionPasajeroGrupo())->setGrupo($vieja->getGrupo()));
    }

    $persistidor = new class($em) implements ProcessorInterface {
        public function __construct(private $em) {}
        public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
        {
            $this->em->persist($data);
            $this->em->flush();
            return $data;
        }
    };

    if ($conArreglo) {
        (new CotizacionFilepasajeroProcessor($persistidor))->process($pasajero, new Patch());
    } else {
        $persistidor->process($pasajero, new Patch());
    }

    echo "\n✅ El flush pasó sin 1062.\n\n";

    $em->clear();
    $recargado = $em->getRepository(CotizacionFilepasajero::class)->find($pasajero->getId());
    $mismasFilas = true;
    foreach ($recargado->getIdentificaciones() as $doc) {
        $previo = $antes[$doc->getTipo()->value] ?? null;
        $mismaFila = $previo !== null && $previo['id'] === (string) $doc->getId();
        $mismasFilas = $mismasFilas && $mismaFila;
        printf(
            "  después %-10s %-15s id=%s  → %s%s\n",
            $doc->getTipo()->value,
            $doc->getNumero(),
            $doc->getId(),
            $mismaFila ? 'MISMA fila (UPDATE)' : '⚠️ fila NUEVA (DELETE+INSERT)',
            $mismaFila && $previo['creado'] === $doc->getCreatedAt()?->format('c') ? ', createdAt intacto' : ''
        );
    }
    foreach ($recargado->getPertenencias() as $pertenencia) {
        $previo = $gruposAntes[(string) $pertenencia->getGrupo()->getId()] ?? null;
        $mismaFila = $previo !== null && $previo === (string) $pertenencia->getId();
        $mismasFilas = $mismasFilas && $mismaFila;
        printf(
            "  después grupo      %-15s id=%s  → %s\n",
            $pertenencia->getGrupo()->getEtiqueta(),
            $pertenencia->getId(),
            $mismaFila ? 'MISMA fila' : '⚠️ fila NUEVA (DELETE+INSERT)'
        );
    }

    printf("\n%s\n", $mismasFilas ? '✅ Todas las filas se reutilizaron.' : '⚠️ Alguna fila se cambió por otra.');
} catch (Throwable $e) {
    printf("\n💥 %s\n   %s\n", $e::class, explode("\n", $e->getMessage())[0]);
}

if ($conn->isTransactionActive()) { $conn->rollBack(); }
echo "\n(rollback: la base queda como estaba)\n";
