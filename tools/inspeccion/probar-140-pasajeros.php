<?php
// ¿Cuántas consultas cuesta serializar un expediente con 140 pasajeros y sus documentos?
// Con 2 pasajeros no se distingue un N+1 de nada. Transacción con rollback.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$conn = $em->getConnection();
$conn->beginTransaction();

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;

$consultas = static fn (): int => (int) $conn->fetchAssociative("SHOW SESSION STATUS LIKE 'Questions'")['Value'];

try {
    $file = $em->getRepository(CotizacionFile::class)->findOneBy([]);
    $pais = $em->getRepository(App\Entity\Maestro\MaestroPais::class)->findOneBy([]);
    printf("Expediente «%s» — pasajeros hoy: %d\n", $file->getNombreGrupo(), $file->getFilepasajeros()->count());

    for ($i = 1; $i <= 140; ++$i) {
        $pax = (new CotizacionFilepasajero())
            ->setNombre('Prueba')->setApellido(sprintf('Numero %03d', $i))
            ->setPais($pais)->setSexo(SexoEnum::from('M'))
            ->setFechanacimiento(new DateTime('2009-05-01'));
        $file->addFilepasajero($pax);
        $em->persist($pax);
        foreach ([DocumentoTipoEnum::DNI, DocumentoTipoEnum::PASAPORTE] as $tipo) {
            $id = (new CotizacionPasajeroIdentificacion())
                ->setTipo($tipo)->setNumero(sprintf('%s%06d', $tipo->value, $i))
                ->setVencimiento(new DateTime('2027-01-01'));
            $pax->addIdentificacion($id);
            $em->persist($id);
        }
    }
    $em->flush();
    $em->clear();

    // El serializer no es público en el contenedor compilado, así que se reproduce su patrón de
    // acceso: recorrer los pasajeros y tocar la colección anidada. Las consultas que salgan de
    // aquí son exactamente las que hará la API.
    $recargado = $em->getRepository(CotizacionFile::class)->find($file->getId());

    $antes = $consultas();
    $t0 = microtime(true);
    $docs = 0;
    foreach ($recargado->getFilepasajeros() as $pax) {
        foreach ($pax->getIdentificaciones() as $ident) { $ident->getNumero(); ++$docs; }
    }
    $ms = (microtime(true) - $t0) * 1000;
    $usadas = $consultas() - $antes - 1;   // -1 por el propio SHOW STATUS

    printf("\nRecorriendo %d pasajeros y sus %d identificaciones:\n", count($recargado->getFilepasajeros()), $docs);
    printf("   consultas ... %d\n", $usadas);
    printf("   tiempo ...... %.0f ms\n", $ms);
    printf("\n   %s\n", $usadas > 100 ? '⚠️ N+1: una consulta por pasajero' : 'sin N+1 aparente');
} finally {
    if ($conn->isTransactionActive()) { $conn->rollBack(); }
    echo "\n(rollback: no se escribió nada)\n";
}
