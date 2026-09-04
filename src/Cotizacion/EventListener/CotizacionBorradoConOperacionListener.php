<?php

declare(strict_types=1);

namespace App\Cotizacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Una propuesta con órdenes ya emitidas no se borra.
 *
 * ── 🔥 La puerta de delante estaba cerrada y la de al lado no ───────────────
 * {@see \App\Operacion\EventListener\OperacionOrdenBorradoListener} impide borrar una orden que
 * ya salió al proveedor: «el documento existió y su rastro se queda». Pero ese guarda vive en el
 * `preRemove` de la **orden**, y borrar la cotización **no borra ninguna orden**: borra sus
 * servicios. `operacion_servicio` cuelga del componente con `ON DELETE CASCADE`, así que la
 * cascada la resuelve MySQL y Doctrine ni se entera. Ningún listener llega a correr.
 *
 * Medido en producción el 04/09/2026, borrando la propuesta de `2KVBMX` en una transacción con
 * rollback:
 *
 * ```
 *                            antes  después
 * cotservicios                  17        0
 * filas de operación            47        0
 * órdenes                        6        6   ← sobreviven
 * órdenes emitidas y VACÍAS      1        6   ← apuntando a nada
 * ítems de esas órdenes         21       21   ← con sus importes
 * enlaces públicos vivos         6        6   ← el proveedor sigue entrando
 * ```
 *
 * No es que se pierda información: es peor. La orden sigue existiendo, con su número, sus líneas
 * y su enlace, diciéndole a un proveedor que preste unos servicios que ya no están en ninguna
 * parte. Y nadie se entera, porque borrar devolvió 204.
 *
 * ── Qué se permite ─────────────────────────────────────────────────────────
 * ```
 * sin órdenes, o sólo BORRADOR   →  se borra. Nada salió: no hay nada que contradecir.
 * EMITIDA/CONFIRMADA/COMPLETADA  →  409. Anula esas órdenes primero.
 * CANCELADA                      →  se borra. Al anular, sus servicios ya volvieron al pool.
 * ```
 *
 * ⚠️ **Se manda a anular, no a borrar la orden**, y es el mismo remedio que da el otro guarda:
 * {@see \App\Operacion\Service\OperacionOrdenEmision::anular()} devuelve los servicios al pool,
 * así que quien quiera deshacerse de la propuesta lo consigue igual — y sin dejar un documento
 * vivo apuntando al vacío.
 *
 * ⚠️ **409 y no 403.** No es una cuestión de permisos —nadie con más rango debería poder hacer
 * esto—: es que el estado actual del expediente lo impide. El mensaje dice qué órdenes son y qué
 * hacer con ellas, porque un «no se puede» sin la lista obliga a buscarlas a mano.
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Cotizacion::class)]
final class CotizacionBorradoConOperacionListener
{
    public function preRemove(Cotizacion $cotizacion, PreRemoveEventArgs $args): void
    {
        // ⚠️ Una consulta y no un recorrido por el grafo: `CotizacionCotcomponente` **no tiene el
        // lado inverso** de `OperacionServicio` —la relación es unidireccional a propósito, el
        // catálogo no sabe de operación— y añadírselo para contar obligaría a hidratar las 47
        // filas. Aquí sólo hacen falta los números de OS.
        //
        // ⚠️ Y va contra la BASE, no contra la colección en memoria: en `preRemove` los hijos
        // están marcados pero todavía no borrados, así que la consulta ve la foto correcta.
        $filas = $args->getObjectManager()->createQuery(
            <<<'DQL'
            SELECT DISTINCT d.numeroOs AS numero, d.estadoOs AS estado
              FROM App\Operacion\Entity\OperacionServicio o
              JOIN o.ordenServicio d
              JOIN o.cotizacionServicio s
             WHERE s.cotizacion = :cotizacion
               AND d.estadoOs != :borrador
            DQL,
        )
            // 🔥 **El id CON su tipo, no la entidad.** `cotizacion_id` es `BINARY(16)`: pasando
            // el objeto, Doctrine compara contra la representación equivocada y la consulta
            // devuelve **cero filas sin un solo error**. Este guarda «funcionaba» —dejaba borrar
            // siempre— hasta que se probó contra datos reales. Es la misma trampa que ya mordió
            // en `AbrirOperativaProcessor`; ahí se resolvió con `ArrayParameterType::BINARY`.
            ->setParameter('cotizacion', $cotizacion->getId(), UuidType::NAME)
            ->setParameter('borrador', EstadoOrdenServicioEnum::BORRADOR)
            ->getArrayResult();

        if ($filas === []) {
            return;
        }

        /** @var list<array{numero: string|null, estado: EstadoOrdenServicioEnum}> $filas */
        $lista = array_map(
            static fn (array $f): string => sprintf('%s (%s)', $f['numero'] ?? '?', $f['estado']->value),
            $filas,
        );

        sort($lista);

        throw new ConflictHttpException(sprintf(
            'Esta propuesta sostiene %d orden%s de servicio que ya salieron al proveedor: %s. '
            . 'Borrarla las dejaría vivas y apuntando a servicios inexistentes. Anúlalas primero '
            . '—eso devuelve sus servicios al pool— y vuelve a intentarlo.',
            count($lista),
            count($lista) === 1 ? '' : 'es',
            implode(', ', $lista),
        ));
    }
}
