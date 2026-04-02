<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use LogicException;

/**
 * Listener de Integridad de Datos.
 * Objetivo: Validar reglas de negocio estrictas antes de guardar en BD.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioIntegrityListener
{
    /**
     * Se ejecuta antes de crear un nuevo registro.
     */
    public function prePersist(PmsEventoCalendario $evento, PrePersistEventArgs $args): void
    {
        $this->validarFechas($evento);
        $this->asegurarCanalDirecto($evento, $args->getObjectManager());
        $this->asegurarEstadoConfirmadoPorPago($evento, $args->getObjectManager());
    }

    /**
     * Se ejecuta antes de actualizar un registro existente.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        $needsRecompute = false;

        // Optimización: Solo validamos si se tocaron las fechas.
        if ($args->hasChangedField('inicio') || $args->hasChangedField('fin')) {
            $this->validarFechas($evento);
        }

        // RED DE SEGURIDAD 1: Canal Directo
        if ($evento->getChannel() === null && !$evento->isOta()) {
            $this->asegurarCanalDirecto($evento, $args->getObjectManager());
            $needsRecompute = true;
        }

        // RED DE SEGURIDAD 2: Si el estado de pago cambió, evaluamos la auto-confirmación
        if ($args->hasChangedField('estadoPago')) {
            if ($this->asegurarEstadoConfirmadoPorPago($evento, $args->getObjectManager())) {
                $needsRecompute = true;
            }
        }

        // 🔥 HACK DOCTRINE: Si alguna de las reglas anteriores mutó la entidad en un preUpdate,
        // debemos decirle al UnitOfWork que re-calcule los cambios para que se guarden en la BD.
        if ($needsRecompute) {
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            $meta = $em->getClassMetadata(PmsEventoCalendario::class);
            $uow->recomputeSingleEntityChangeSet($meta, $evento);
        }
    }

    /**
     * Inyecta el Canal Directo si el evento no es OTA y se quedó sin canal.
     */
    private function asegurarCanalDirecto(PmsEventoCalendario $evento, ObjectManager $em): void
    {
        if (!$evento->isOta() && $evento->getChannel() === null) {
            // Usamos getReference para evitar una consulta SQL innecesaria
            $canalDirecto = $em->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO);

            if ($canalDirecto) {
                $evento->setChannel($canalDirecto);
            }
        }
    }

    /**
     * Regla de Negocio: Si el estado de pago pasa a ser cualquiera distinto de "no pagado",
     * el evento debe pasar automáticamente a estado "Confirmada".
     * * @return bool True si se realizó una mutación en la entidad, False si no se hizo nada.
     */
    private function asegurarEstadoConfirmadoPorPago(PmsEventoCalendario $evento, ObjectManager $em): bool
    {
        $estadoPago = $evento->getEstadoPago();
        $estadoActual = $evento->getEstado();

        // Si faltan relaciones maestras, abortamos la regla (prevención de errores)
        if (!$estadoPago || !$estadoActual) {
            return false;
        }

        // Si el estado de pago es CUALQUIERA MENOS "no pagado" (ID_SIN_PAGO)
        if ($estadoPago->getId() !== PmsEventoEstadoPago::ID_SIN_PAGO) {

            // Y si la reserva NO está ya confirmada (para no hacer queries redundantes)
            if ($estadoActual->getId() !== PmsEventoEstado::CODIGO_CONFIRMADA) {

                // Usamos getReference para inyectar el estado sin disparar un SELECT a la BD
                $estadoConfirmada = $em->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_CONFIRMADA);

                if ($estadoConfirmada) {
                    $evento->setEstado($estadoConfirmada);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Lógica central de validación.
     * Lanza LogicException para detener la transacción inmediatamente si hay incoherencia.
     */
    private function validarFechas(PmsEventoCalendario $evento): void
    {
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        // Validamos solo si ambas fechas existen
        if ($inicio && $fin) {

            // REGLA: Fin debe ser estrictamente mayor que Inicio.
            if ($fin <= $inicio) {

                // Obtenemos el ID para el log (si es persist, podría ser null/nuevo)
                $id = $evento->getId() ? (string) $evento->getId() : 'NUEVO';
                $unidad = $evento->getPmsUnidad() ? $evento->getPmsUnidad()->getNombre() : 'Sin Unidad';

                throw new LogicException(sprintf(
                    'ERROR DE INTEGRIDAD (Evento #%s | %s): La fecha de fin (%s) no puede ser igual o anterior a la de inicio (%s). ' .
                    'Beds24 requiere una duración mínima de 1 minuto/noche. Operación abortada.',
                    $id,
                    $unidad,
                    $fin->format('Y-m-d H:i'),
                    $inicio->format('Y-m-d H:i')
                ));
            }
        }
    }
}