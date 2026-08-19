<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Dispatch\RevisarOrdenDelNombreDispatch;
use App\Pms\Entity\PmsReserva;
use App\Pms\Nombre\OrdenDelNombre;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Encola la revisión del orden de nombre y apellido cuando entra o cambia el de una reserva.
 *
 * ### Por qué un listener y no una llamada dentro del pull
 *
 * `BookingPullPersister::upsert()` construye entidades pero **no flushea**: quien lo hace es
 * quien lo llama. Encolar ahí dejaría el trabajo apuntando a una reserva que todavía puede no
 * existir. En `onFlush` el id ya está —los UUID v7 se asignan antes— y en `postFlush` la fila
 * ya está escrita, que es cuando el handler podrá leerla.
 *
 * Y de paso cubre las dos puertas de una vez: el cron del pull y el fast-track del webhook
 * pasan los dos por el mismo `upsert()`, pero también entra por aquí lo que teclea un operador.
 *
 * ### El corta-bucles
 *
 * El handler guarda el intercambio, ese guardado vuelve a despertar a este listener, y sin
 * freno se encolaría otra revisión para siempre. {@see OrdenDelNombre::esNuestroIntercambio()}
 * lo corta comparando los dos pares como conjunto: si son las mismas dos cadenas cambiadas de
 * sitio, el cambio lo hicimos nosotros y no se vuelve a preguntar. No vale con mirar «cambió el
 * nombre»: un operador corrigiendo una tilde también lo cambia, y ése sí hay que revisarlo.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PmsNombreOrdenListener
{
    /** @var list<RevisarOrdenDelNombreDispatch> */
    private array $pendientes = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entidad) {
            if ($entidad instanceof PmsReserva) {
                $this->recolectar($entidad);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof PmsReserva) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entidad);

            // Sólo interesa si se tocó alguno de los dos campos.
            if (!isset($cambios['nombreCliente']) && !isset($cambios['apellidoCliente'])) {
                continue;
            }

            $antesNombre = $cambios['nombreCliente'][0] ?? $entidad->getNombreCliente();
            $antesApellido = $cambios['apellidoCliente'][0] ?? $entidad->getApellidoCliente();

            if (OrdenDelNombre::esNuestroIntercambio(
                is_string($antesNombre) ? $antesNombre : null,
                is_string($antesApellido) ? $antesApellido : null,
                $entidad->getNombreCliente(),
                $entidad->getApellidoCliente()
            )) {
                continue;
            }

            $this->recolectar($entidad);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pendientes === []) {
            return;
        }

        // Se vacía ANTES de despachar: si el bus lanzara, un pendiente que se quedara dentro
        // se reenviaría en el siguiente flush de la misma petición.
        $trabajos = $this->pendientes;
        $this->pendientes = [];

        foreach ($trabajos as $trabajo) {
            $this->bus->dispatch($trabajo);
        }
    }

    private function recolectar(PmsReserva $reserva): void
    {
        $nombre = trim((string) $reserva->getNombreCliente());
        $apellido = trim((string) $reserva->getApellidoCliente());

        if (!OrdenDelNombre::mereceRevision($nombre, $apellido)) {
            return;
        }

        $id = $reserva->getId();

        if ($id === null) {
            return;
        }

        $this->pendientes[] = new RevisarOrdenDelNombreDispatch((string) $id, $nombre, $apellido);
    }
}
