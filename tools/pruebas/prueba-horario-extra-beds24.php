<?php

declare(strict_types=1);

/**
 * Prueba del horario extra contra Beds24 REAL, paso a paso. ⚠️ ESCRIBE: cada paso guarda y la
 * cola lo empuja al canal. Sólo para una casita y fechas que estén libres y acordadas (la primera
 * vez: Casita 1, 02–05/02/2027, decisión de Jorge del 01/10/2026).
 *
 * La estancia de prueba no tiene reserva a propósito: así no nace ninguna ficha financiera, ni
 * conversación, ni mensaje a nadie. En Beds24 sale como «Evento (Confirmada)» y su noche extra
 * como «Entrada temprana · PRUEBA horario extra».
 *
 *   php tools/pruebas/prueba-horario-extra-beds24.php crear "Casita 1" 2027-02-02 2027-02-05
 *   php tools/pruebas/prueba-horario-extra-beds24.php mover-dia     <evento> [+1 day]
 *   php tools/pruebas/prueba-horario-extra-beds24.php mover-casita  <evento> "Casita 2"
 *   php tools/pruebas/prueba-horario-extra-beds24.php desmarcar     <evento>
 *   php tools/pruebas/prueba-horario-extra-beds24.php marcar        <evento>
 *   php tools/pruebas/prueba-horario-extra-beds24.php cancelar      <evento>
 *   php tools/pruebas/prueba-horario-extra-beds24.php ver           <evento>
 *
 * Después de cada paso, `ver` enseña los links con su `bookId` y el estado de su última cola: el
 * push sale solo (worker), y hay que esperar a que pase a `success` antes de mirar Beds24.
 */

use App\Kernel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$factory = new PmsEventoCalendarioFactory($em);

$paso = $argv[1] ?? 'ayuda';
$unidad = static fn (string $nombre): PmsUnidad => $em->getRepository(PmsUnidad::class)->findOneBy(['nombre' => $nombre])
    ?? throw new RuntimeException("No existe la casita «{$nombre}».");
$evento = static fn (string $id): PmsEventoCalendario => $em->find(PmsEventoCalendario::class, $id)
    ?? throw new RuntimeException("No existe el evento {$id}.");

$ver = static function (PmsEventoCalendario $e): void {
    printf("%s · %s %s→%s · entrada temprana %s · %s\n", $e->getId(), $e->getPmsUnidad()?->getNombre(),
        $e->getInicio()?->format('d/m H:i'), $e->getFin()?->format('d/m H:i'), $e->isEntradaTemprana() ? 'sí' : 'no', $e->getEstado()?->getId());
    foreach ($e->getBeds24Links() as $link) {
        $ultima = null;
        foreach ($link->getQueues() as $q) {
            $ultima = $ultima === null || $q->getUpdatedAt() > $ultima->getUpdatedAt() ? $q : $ultima;
        }
        printf("  %-13s %-5s %-6s room %-6s bookId %-9s cola %s%s\n", $link->getRol(), $link->isEsPrincipal() ? 'PRINC' : '',
            $link->getUnidadBeds24Map()?->getVirtualEstablecimiento()?->getCodigo(), $link->getUnidadBeds24Map()?->getBeds24RoomId(),
            $link->getBeds24BookId() ?? '-', $ultima?->getStatus() ?? '-', $ultima?->getFailedReason() ? ' (' . $ultima->getFailedReason() . ')' : '');
    }
};

switch ($paso) {
    case 'crear':
        [, , $casita, $desde, $hasta] = $argv + [null, null, 'Casita 1', '2027-02-02', '2027-02-05'];
        $e = (new PmsEventoCalendario())
            ->setPmsUnidad($unidad($casita))
            ->setEstado($em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA))
            ->setEstadoPago($em->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO))
            ->setInicio(new DateTimeImmutable("$desde 09:00"))
            ->setFin(new DateTimeImmutable("$hasta 10:00"))
            ->setTituloCache('PRUEBA horario extra')
            ->setDescripcion('Prueba del horario extra contra Beds24 (docs/PlanHorarioExtraSinEventos.md). Se puede borrar.')
            ->setEntradaTemprana(true);
        $factory->hydrateLinksForUi($e);
        $em->persist($e);
        $em->flush();
        $ver($e);
        break;

    case 'mover-dia':
        $e = $evento($argv[2] ?? '');
        $salto = $argv[3] ?? '+1 day';
        $e->setInicio($e->getInicio()?->modify($salto))->setFin($e->getFin()?->modify($salto));
        $em->flush();
        $ver($e);
        break;

    case 'mover-casita':
        $e = $evento($argv[2] ?? '');
        $e->setPmsUnidad($unidad($argv[3] ?? 'Casita 2'));
        $factory->hydrateLinksForUi($e);
        $em->flush();
        $ver($e);
        break;

    case 'desmarcar':
    case 'marcar':
        $e = $evento($argv[2] ?? '');
        $e->setEntradaTemprana($paso === 'marcar');
        $em->flush();
        $ver($e);
        break;

    case 'cancelar':
        $e = $evento($argv[2] ?? '');
        $e->setEstado($em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CANCELADA));
        $em->flush();
        $ver($e);
        break;

    case 'ver':
        $ver($evento($argv[2] ?? ''));
        break;

    default:
        echo "Pasos: crear | mover-dia | mover-casita | desmarcar | marcar | cancelar | ver. Ver la cabecera.\n";
}
