<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Sólo se borra lo que **todavía no salió**.
 *
 * ── El agujero ──────────────────────────────────────────────────────────────
 * `DELETE /ops/orden_servicios/{id}` no tenía ninguna comprobación: bastaba el permiso para
 * borrar una orden **ya emitida**, la que el proveedor tiene en la mano.
 *
 * Y eso contradecía de frente a {@see \App\Operacion\Service\OperacionOrdenEmision::validarTransicion()},
 * que se niega a devolver una emitida a borrador con estas palabras: «el proveedor ya la tiene.
 * Anúlala y emite otra». Si ni siquiera se puede retroceder de estado, borrarla entera —número
 * de OS, líneas congeladas, bitácora, pagos— no puede estar permitido. Era la puerta grande al
 * lado de la puerta cerrada.
 *
 * ── Qué se puede hacer con cada estado ──────────────────────────────────────
 * ```
 * BORRADOR                       →  se BORRA. No salió: no hay nada que contradecir.
 * EMITIDA/CONFIRMADA/COMPLETADA  →  se ANULA. El documento existió y su rastro se queda.
 * CANCELADA                      →  se queda. Es el rastro.
 * ```
 *
 * ⚠️ **Anular y borrar no son lo mismo, y por eso el mensaje manda al sitio correcto.** Anular
 * ({@see \App\Operacion\Service\OperacionOrdenEmision::anular()}) también devuelve los servicios
 * al pool, así que quien quería «soltar los servicios» lo consigue igual — y sin perder la
 * constancia de que hubo una orden.
 *
 * ── Los servicios vuelven al pool solos ─────────────────────────────────────
 * `operacion_servicio.orden_servicio_id` es `ON DELETE SET NULL`, así que borrar el borrador
 * suelta sus servicios sin tocarlos. Los pagos y la bitácora sí caen en cascada, que es lo
 * correcto: son de la orden, no del servicio.
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: OperacionOrdenServicio::class)]
final class OperacionOrdenBorradoListener
{
    public function preRemove(OperacionOrdenServicio $orden, PreRemoveEventArgs $args): void
    {
        $estado = $orden->getEstadoOs();

        if ($estado === EstadoOrdenServicioEnum::BORRADOR) {
            return;
        }

        throw new AccessDeniedHttpException(sprintf(
            'La orden %s está %s: no se borra. Una orden que ya salió al proveedor deja rastro. '
            . 'Anúlala —eso también devuelve sus servicios al pool— y, si hace falta, emite otra.',
            $orden->getNumeroOs(),
            $estado->value
        ));
    }
}
