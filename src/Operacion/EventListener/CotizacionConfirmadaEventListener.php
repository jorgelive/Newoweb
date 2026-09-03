<?php

declare(strict_types=1);

namespace App\Operacion\EventListener;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOperacionEnum;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

#[AsDoctrineListener(event: Events::onFlush)]
class CotizacionConfirmadaEventListener
{
    // ⚠️ Sin dependencias: desde que confirmar no genera, este listener sólo mueve estados. Si
    // alguien vuelve a inyectar aquí `BibliaSnapshotService`, está reintroduciendo la generación
    // automática que se quitó a propósito el 02/09/2026 — léase el docblock de reactivar().

    /**
     * Intercepta el proceso de sincronización con la base de datos para evaluar
     * cambios de estado en Cotizacion.
     *
     * Utilizar onFlush es la estrategia recomendada por Doctrine para persistir o modificar
     * otras entidades (OperacionServicio) en reacción a un cambio, garantizando que todo
     * ocurra en la misma transacción sin causar bucles infinitos por flushes anidados.
     *
     * @param OnFlushEventArgs $args Argumentos proporcionados por Doctrine durante el flush.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        // Iterar únicamente sobre las entidades que tienen actualizaciones programadas
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Cotizacion) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);

            // Validar si el campo 'estado' fue uno de los que cambió
            if (!isset($changeSet['estado'])) {
                continue;
            }

            // $changeSet['estado'][0] es el valor viejo, [1] es el nuevo valor
            $nuevoEstado = $changeSet['estado'][1];

            // Si Doctrine devuelve un string en lugar del Enum debido a la configuración de mapeo, lo parseamos
            if (is_string($nuevoEstado)) {
                $nuevoEstado = CotizacionEstadoEnum::tryFrom($nuevoEstado);
            }

            // Salir de CONFIRMADO cancela la operación; volver a entrar la reactiva.
            //
            // Antes, des-confirmar dejaba las filas en `pendiente`: con aspecto de
            // activas, sin atenuar y dentro de los filtros habituales. Al confirmar la
            // versión siguiente del mismo expediente —renegociar es rutina— el cuadro
            // mostraba los mismos días DOS veces, y la reconciliación no lo arreglaba
            // porque trabaja por cotización y nunca ve las filas de la otra versión.
            // Riesgo de pedirle y pagarle dos veces lo mismo al proveedor.
            match ($nuevoEstado) {
                CotizacionEstadoEnum::CONFIRMADO => $this->reactivar($entity, $em, $uow),
                CotizacionEstadoEnum::CANCELADO,
                CotizacionEstadoEnum::PENDIENTE,
                CotizacionEstadoEnum::ENVIADO,
                CotizacionEstadoEnum::CERRADO    => $this->propagarEstadoOperacion($entity, $em, $uow, EstadoOperacionEnum::CANCELADO),
                // ⚠️ Explícito, no por el `default`. Un histórico nace ya congelado —el processor
                // lo inserta, no lo transiciona— así que en la práctica esta rama no se pisa; pero
                // el día que alguien mande a histórico una cotización viva, sus filas tienen que
                // cancelarse como con cualquier otro estado no confirmado. Caer en el `default`
                // las dejaría activas sin que nada lo denunciara.
                CotizacionEstadoEnum::HISTORICO  => $this->propagarEstadoOperacion($entity, $em, $uow, EstadoOperacionEnum::CANCELADO),

                // ⚠️ La OPERATIVA es donde vive la operación: sus filas van ACTIVAS, igual que
                // las de una confirmada. Explícito y no por el `default`, que aquí significa «no
                // toques nada» — y para una operativa eso dejaría sus filas canceladas.
                //
                // 🔥 Y merece quedar escrito: al añadir este `case`, **PHPStan no dijo nada**. Los
                // `match` exhaustivos del enum sí saltan, pero éste tiene `default`, así que un
                // estado nuevo entra en silencio. Es lo que ya avisaba el comentario de abajo, y
                // se cumplió a la primera.
                CotizacionEstadoEnum::OPERATIVA  => $this->reactivar($entity, $em, $uow),

                // ⚠️ Sólo lo pisa OPERADO, y ahí «no tocar nada» es lo correcto: el viaje ya
                // ocurrió y sus filas se quedan como están. Cualquier estado nuevo va ARRIBA, con
                // su caso; caer aquí por olvido es dejar filas activas sin que nada lo denuncie.
                default                          => null,
            };
        }
    }

    /**
     * Reactiva las filas de operación que ya existan. **NO genera ninguna.**
     *
     * ── ⚠️ Confirmar ya NO crea la operación (02/09/2026) ───────────────────
     * Hasta hoy, pasar a CONFIRMADO generaba las filas de La Biblia de golpe. Se separó a
     * petición del operador: **generar es una decisión suya, no un efecto de vender.** Confirmar
     * es un acto comercial —el cliente aceptó— y puede ocurrir mucho antes de que la operación
     * esté lista para armarse; encadenarlas obligaba a que el cuadro naciera con lo que hubiera
     * en ese momento y a corregirlo después.
     *
     * La generación vive ahora en `GenerarOperacionProcessor`, con su botón.
     *
     * ── Lo que SÍ se queda: reactivar ───────────────────────────────────────
     * Reactivar no es generar, y quitarlo sería otra cosa distinta de lo que se pidió. Sin ello,
     * cancelar una cotización por error y volver a confirmarla al minuto dejaba las 42 filas en
     * `cancelado` **para siempre**: la reconciliación tampoco lo arregla, porque `estadoOperacion`
     * está en la lista de campos que jamás toca — es del operador.
     *
     * O sea: lo que existía, vuelve. Lo que no existe, no nace solo.
     */
    private function reactivar(Cotizacion $cotizacion, EntityManagerInterface $em, UnitOfWork $uow): void
    {
        $this->propagarEstadoOperacion($cotizacion, $em, $uow, EstadoOperacionEnum::PENDIENTE);
    }

    private function propagarEstadoOperacion(
        Cotizacion $cotizacion,
        EntityManagerInterface $em,
        UnitOfWork $uow,
        EstadoOperacionEnum $estado,
    ): void {
        $cotservicios = $cotizacion->getCotservicios()->toArray();
        if ($cotservicios === []) {
            return;
        }

        // Se busca por la colección de cotservicios y no con un WHERE cs.cotizacion = :cot:
        // el id es un Uuid binario y la comparación en DQL no lo convierte, así que esa
        // consulta devolvía 0 filas en silencio y cancelar una cotización no propagaba nada.
        /** @var OperacionServicio[] $servicios */
        $servicios = $em->getRepository(OperacionServicio::class)
            ->findBy(['cotizacionServicio' => $cotservicios]);

        if (empty($servicios)) {
            return;
        }

        foreach ($servicios as $ops) {
            // Lo ya COMPLETADO no se toca, en ningún sentido. Un servicio que se operó
            // el martes se operó, y que el viernes se archive la cotización no lo
            // deshace: pisarlo con `pendiente` o `cancelado` borraría el registro de lo
            // que de verdad ocurrió, que es justo lo que este campo existe para guardar.
            if ($ops->getEstadoOperacion() === EstadoOperacionEnum::COMPLETADO) {
                continue;
            }

            if ($ops->getEstadoOperacion() === $estado) {
                continue;   // ya está: no ensuciar el changeset
            }

            $ops->setEstadoOperacion($estado);

            // Recalcular los cambios para la entidad actualizada dentro del proceso de flush en curso
            $uow->computeChangeSet($em->getClassMetadata(OperacionServicio::class), $ops);
        }
    }
}
