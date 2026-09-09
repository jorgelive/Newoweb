<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Operacion\Entity\OperacionServicio;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Cuando un componente pasa a «horario libre», su hora de recojo deja de significar nada.
 *
 * ── El caso ─────────────────────────────────────────────────────────────────
 *
 * Una orden emitida el 08/09/2026 le decía al proveedor «🕐 10:00 · Almuerzo en Urubamba» sobre un
 * componente marcado como horario libre. El 10:00 no era la hora del almuerzo: era una
 * `horaRecojo` que había quedado huérfana, y el documento la imprime porque `OperacionOrdenEmision`
 * hace `getHoraRecojo() ?? getHoraComponente()`.
 *
 * Peor que enseñarla: se copió a `horaRecojoConfirmada`, y ese campo significa «el proveedor
 * confirmó esta hora». La orden afirmaba que Tunupa había confirmado un recojo que nadie le
 * preguntó.
 *
 * ── Por qué nadie lo limpiaba ───────────────────────────────────────────────
 *
 * `horaRecojo` es **campo del operador**, y eso está decidido y escrito:
 * `BibliaReconciliacionService` lo excluye de los campos que gobierna la cotización —«no los toca
 * jamás»— y `BibliaSnapshotService` dejó de snapshotearlo. Nadie lo escribe salvo una persona, por
 * un único input.
 *
 * Y ese input se pinta con `v-if="admiteHora(servicio)"`, que mira este mismo flag. Así que al
 * marcar el componente como horario libre pasan tres cosas a la vez: se vacía `horaComponente`,
 * **desaparece el control** que podría limpiar la hora de recojo, y el valor se queda. Invisible en
 * la pantalla y mandando en el documento.
 *
 * ── Por qué se arregla AQUÍ y no al emitir ──────────────────────────────────
 *
 * Se consideró que `sinHorario` mandara sobre la hora al emitir, y se descartó: eso tapa un dato
 * que no debería existir y lo deja para la siguiente vía que lo lea. Éste es el instante exacto en
 * que el dato se queda huérfano, y el único sitio donde se sabe que lo está.
 *
 * El campo sigue siendo del operador **mientras el componente admita hora**. Cuando deja de
 * admitirla, no es que el operador pierda su dato: es que el dato deja de significar algo.
 *
 * ⚠️ **Sólo en la transición `false → true`.** Un componente que ya nació sin horario no tiene
 * nada que limpiar, y uno que pasa a admitir hora tampoco: ahí el operador vuelve a tener su campo
 * y lo que escriba es suyo.
 *
 * ⚠️ **No alcanza al reparador de coherencia**, que marca `sin_horario` por SQL crudo y se salta
 * los listeners —la trampa de siempre—. Ahí está decidido a propósito no tocarlo: el caso que
 * repara son alojamientos, y la hora de llegada a un hotel sí significa algo aunque la estadía no
 * tenga horario. Ver `CoherenciaCatalogoChecker`, caso `alojamiento-con-hora`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class HorarioLibreLimpiaHoraRecojoListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadatos = $em->getClassMetadata(OperacionServicio::class);

        foreach ($uow->getScheduledEntityUpdates() as $entidad) {
            if (!$entidad instanceof CotizacionCotcomponente) {
                continue;
            }

            $cambios = $uow->getEntityChangeSet($entidad);

            // Sólo la transición hacia horario libre. `[viejo, nuevo]` es la forma del changeset
            // para un escalar; para una colección sería otra cosa, pero esto es un booleano.
            if (($cambios['sinHorario'][1] ?? null) !== true || ($cambios['sinHorario'][0] ?? null) !== false) {
                continue;
            }

            $servicios = $em->getRepository(OperacionServicio::class)
                ->findBy(['cotizacionComponente' => $entidad]);

            foreach ($servicios as $servicio) {
                if ($servicio->getHoraRecojo() === null) {
                    continue;
                }

                $servicio->setHoraRecojo(null);

                // ⚠️ Dentro de `onFlush` hay que recalcular a mano: el cambio llega tarde para el
                // cálculo automático de changesets y se perdería sin decir nada.
                $uow->recomputeSingleEntityChangeSet($metadatos, $servicio);
            }
        }
    }
}
