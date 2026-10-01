<?php

declare(strict_types=1);

/**
 * Ensayo del horario extra sin eventos hermanos, con el ORM y los listeners de verdad, y SIN
 * ESCRIBIR NADA: todo va dentro de una transacción que se deshace al final. No sale nada a
 * Beds24 (las colas y los mensajes de Messenger se quedan en la transacción).
 *
 * Recorre lo que hay que comprobar en el corte (docs/PlanHorarioExtraSinEventos.md, fase 4):
 * marcar, mover de día, mover de casita, desmarcar, volver a marcar, cancelar — y que el solape
 * frene a quien pisa una noche extra. En cada paso enseña los links y lo que se mandaría a Beds24.
 *
 *   php tools/pruebas/ensayar-horario-extra.php ["Casita 1"] [2027-02-02] [2027-02-05] ["Casita 2"]
 */

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Service\Common\HomogeneousBatch;
use App\Kernel;
use App\Pms\Entity\PmsBookingsPushQueue;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Pms\Service\Exchange\Tasks\BookingsPush\BookingsPushMappingStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$factory = new PmsEventoCalendarioFactory($em);
$push = new BookingsPushMappingStrategy();

[, $casita, $desde, $hasta, $otraCasita] = $argv + [null, 'Casita 1', '2027-02-02', '2027-02-05', 'Casita 2'];
$unidad = $em->getRepository(PmsUnidad::class)->findOneBy(['nombre' => $casita]) ?? exit("No existe $casita\n");
$otra = $em->getRepository(PmsUnidad::class)->findOneBy(['nombre' => $otraCasita]) ?? exit("No existe $otraCasita\n");

$em->getConnection()->beginTransaction();

$ver = static function (string $paso, PmsEventoCalendario $e) use ($push): void {
    printf("\n── %s ── %s %s→%s, entrada temprana %s, estado %s\n", $paso, $e->getPmsUnidad()?->getNombre(),
        $e->getInicio()?->format('d/m'), $e->getFin()?->format('d/m'), $e->isEntradaTemprana() ? 'sí' : 'no', $e->getEstado()?->getId());

    foreach ($e->getBeds24Links() as $link) {
        $cola = null;
        foreach ($link->getQueues() as $q) {
            if ($q->getStatus() === PmsBookingsPushQueue::STATUS_PENDING) {
                $cola = $q;
            }
        }
        $payload = '(sin cola pendiente)';
        if ($cola instanceof PmsBookingsPushQueue) {
            $mapeo = $push->map(new HomogeneousBatch($cola->getConfig(), $cola->getEndpoint(), [$cola]));
            $p = $mapeo->payload[0] ?? null;
            $payload = $p === null ? '(se salta)' : json_encode(array_intersect_key($p, array_flip(['id', 'roomId', 'arrival', 'departure', 'status', 'firstName', 'custom2'])), JSON_UNESCAPED_UNICODE);
        }
        printf("  %-13s %-5s mapa %-10s room %-6s id %-9s → %s\n", $link->getRol(), $link->isEsPrincipal() ? 'PRINC' : '',
            $link->getUnidadBeds24Map()?->getVirtualEstablecimiento()?->getCodigo(), $link->getUnidadBeds24Map()?->getBeds24RoomId(),
            $link->getBeds24BookId() ?? '-', $payload);
    }
};

// Las colas se marcan como enviadas y los links reciben un id, como haría Beds24 al contestar.
$enviar = static function (PmsEventoCalendario $e) use ($em): void {
    static $id = 99000000;
    foreach ($e->getBeds24Links() as $link) {
        $link->setBeds24BookId($link->getBeds24BookId() ?? (string) ++$id);
        foreach ($link->getQueues() as $q) {
            if ($q->getStatus() === PmsBookingsPushQueue::STATUS_PENDING) {
                $q->setStatus(PmsBookingsPushQueue::STATUS_SUCCESS);
            }
        }
    }
    $em->flush();
};

try {
    $e = (new PmsEventoCalendario())
        ->setPmsUnidad($unidad)
        ->setEstado($em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA))
        ->setEstadoPago($em->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO))
        ->setInicio(new DateTimeImmutable("$desde 09:00"))
        ->setFin(new DateTimeImmutable("$hasta 10:00"))
        ->setTituloCache('Ensayo Horario Extra')
        ->setEntradaTemprana(true);
    $factory->hydrateLinksForUi($e);
    $em->persist($e);
    $em->flush();
    $ver('1. nace con entrada temprana', $e);
    $enviar($e);

    $e->setInicio($e->getInicio()?->modify('+7 days'))->setFin($e->getFin()?->modify('+7 days'));
    $em->flush();
    $ver('2. una semana más tarde', $e);
    $enviar($e);

    $e->setPmsUnidad($otra);
    $factory->hydrateLinksForUi($e);
    $em->flush();
    $ver("3. a $otraCasita", $e);
    $enviar($e);

    $e->setEntradaTemprana(false);
    $em->flush();
    $ver('4. desmarcada', $e);
    $enviar($e);

    $e->setEntradaTemprana(true);
    $em->flush();
    $ver('5. marcada otra vez', $e);
    $enviar($e);

    // 6. Alguien intenta ocupar la víspera de la entrada temprana.
    $vispera = $e->nocheExtra('extra_entrada')?->desde ?? throw new RuntimeException('Sin noche extra');
    try {
        $intruso = (new PmsEventoCalendario())
            ->setPmsUnidad($otra)
            ->setEstado($em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA))
            ->setEstadoPago($em->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO))
            ->setInicio($vispera->modify('-2 days')->setTime(14, 0))
            ->setFin($vispera->modify('+1 day')->setTime(10, 0));
        $em->persist($intruso);
        echo "\n── 6. otra estancia sobre la víspera: SE DEJÓ CREAR (mal)\n";
        $em->detach($intruso);
    } catch (DomainException $ex) {
        echo "\n── 6. otra estancia sobre la víspera: FRENADA — {$ex->getMessage()}\n";
    }

    $e->setEstado($em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CANCELADA));
    $em->flush();
    $ver('7. estancia cancelada', $e);
} finally {
    $em->getConnection()->rollBack();
    echo "\n(deshecho: no se ha escrito nada)\n";
}
