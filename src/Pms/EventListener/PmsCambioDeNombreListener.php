<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Contract\Nombre\CorreccionDeNombre;
use App\Contract\Nombre\PropagadorDeNombre;
use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Avisa de que el nombre de un huésped cambió. **No sabe quién le escucha, y ése es el punto.**
 *
 * El PMS no puede llamar a Finanzas ni al maestro de contactos: incorporar un sitio que copie el
 * nombre tiene que ser crear una clase que implemente {@see \App\Contract\Nombre\CopiaDelNombre},
 * sin tocar esto. Aquí sólo se detecta el cambio y se cuenta.
 *
 * ### Por qué se recoge en `onFlush` y se reparte en `postFlush`
 *
 * El changeset —lo que decía **antes**— sólo existe durante `onFlush`, y es justo el dato que
 * necesitan las copias para reconocer cuáles escribieron ellas. Pero repartir ahí obligaría a cada
 * implementación a saber de `recomputeSingleEntityChangeSet()`, que es una trampa de Doctrine que
 * no tiene por qué conocer quien sólo guarda un nombre en su tabla. En `postFlush` la fila ya está
 * escrita y cada copia hace su `flush()` normal.
 *
 * ⚠️ **Ese `flush()` de las copias vuelve a disparar este listener, y no hay bucle**: lo que
 * cambian son sus propias tablas, no `PmsReserva`, así que no hay `nombreCliente` en el changeset
 * y no se recolecta nada. El corte es estructural, no un contador de vueltas.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PmsCambioDeNombreListener
{
    /** @var list<CorreccionDeNombre> */
    private array $pendientes = [];

    public function __construct(private readonly PropagadorDeNombre $propagador) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof PmsReserva) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entidad);

            if (!isset($cambios['nombreCliente']) && !isset($cambios['apellidoCliente'])) {
                continue;
            }

            $id = $entidad->getId();

            if ($id === null) {
                continue;
            }

            $antesNombre = $cambios['nombreCliente'][0] ?? $entidad->getNombreCliente();
            $antesApellido = $cambios['apellidoCliente'][0] ?? $entidad->getApellidoCliente();

            $this->pendientes[] = new CorreccionDeNombre(
                origenTipo: 'pms_reserva',
                origenId: (string) $id,
                nombreAntes: is_string($antesNombre) ? $antesNombre : '',
                apellidoAntes: is_string($antesApellido) ? $antesApellido : '',
                nombreAhora: (string) $entidad->getNombreCliente(),
                apellidoAhora: (string) $entidad->getApellidoCliente(),
            );
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pendientes === []) {
            return;
        }

        // Se vacía ANTES de repartir: si una copia lanzara, un pendiente que se quedara dentro se
        // volvería a repartir en el siguiente flush de la misma petición. Mismo cuidado que
        // `PmsNombreOrdenListener`.
        $trabajos = $this->pendientes;
        $this->pendientes = [];

        foreach ($trabajos as $correccion) {
            $this->propagador->propagar($correccion);
        }
    }
}
