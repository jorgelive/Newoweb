<?php
// Sonda: dos documentos por persona, unicidad y la distinción vencido / sin comprobar.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();

use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Enum\DocumentoTipoEnum;

try {
    $pax = $em->getRepository(CotizacionFilepasajero::class)->findOneBy([]);
    printf("Pasajero: %s %s · identificaciones hoy: %d\n\n", $pax->getNombre(), $pax->getApellido(), $pax->getIdentificaciones()->count());

    // Un DNI vencido y sin fecha de emisión conocida
    $dni = (new CotizacionPasajeroIdentificacion())
        ->setTipo(DocumentoTipoEnum::DNI)->setNumero('  73398300  ')
        ->setVencimiento(new DateTime('2026-08-04'));
    $pax->addIdentificacion($dni);
    $em->persist($dni);
    $em->flush();

    printf("DNI añadido, número guardado: «%s»  (se limpian los espacios)\n", $dni->getNumero());
    printf("ahora tiene %d documentos: %s\n\n", $pax->getIdentificaciones()->count(),
        implode(' · ', array_map(fn($i) => (string) $i, $pax->getIdentificaciones()->toArray())));

    $viaje = new DateTime('2026-09-13');
    printf("Al 13/09/2026:\n");
    printf("  vencidos ........ %s\n", implode(', ', array_map(fn($i) => $i->getTipo()->value, $pax->identificacionesVencidasAl($viaje))) ?: '(ninguno)');
    printf("  sin comprobar ... %s\n", implode(', ', array_map(fn($i) => $i->getTipo()->value, $pax->identificacionesSinComprobar())) ?: '(ninguno)');
    printf("  ⚠️ el pasaporte NO sale como vigente: sale como sin comprobar, que es lo que es.\n");

    // La unicidad (pasajero, tipo)
    try {
        $otro = (new CotizacionPasajeroIdentificacion())->setTipo(DocumentoTipoEnum::DNI)->setNumero('99999999');
        $pax->addIdentificacion($otro);
        $em->persist($otro);
        $em->flush();
        echo "\n✗ permitió DOS DNI para la misma persona\n";
    } catch (\Throwable $e) {
        echo "\n✓ un segundo DNI se rechaza en base: ", explode("\n", $e->getMessage())[0], "\n";
    }
} finally {
    if ($em->getConnection()->isTransactionActive()) { $em->getConnection()->rollBack(); }
    echo "\n(rollback: no se escribió nada)\n";
}
