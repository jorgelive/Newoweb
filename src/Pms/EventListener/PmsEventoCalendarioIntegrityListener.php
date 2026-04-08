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
 * Listener de Integridad de Datos y Automatización de Negocio.
 * Valida la coherencia estructural de la reserva (fechas, canales) y automatiza
 * los cambios de estado vinculados a los pagos sin violar las reglas de seguridad.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: PmsEventoCalendario::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: PmsEventoCalendario::class)]
final class PmsEventoCalendarioIntegrityListener
{
    /**
     * Se ejecuta antes de crear un nuevo registro en la base de datos.
     * Garantiza que la reserva nazca con integridad total.
     */
    public function prePersist(PmsEventoCalendario $evento, PrePersistEventArgs $args): void
    {
        $this->validarFechas($evento);
        $this->asegurarCanalDirecto($evento, $args->getObjectManager());
        $this->asegurarEstadoConfirmadoPorPago($evento, $args->getObjectManager());
    }

    /**
     * Se ejecuta antes de actualizar un registro existente.
     * Intercepta los cambios para aplicar reglas de negocio y re-calcula el UnitOfWork si es necesario.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        $needsRecompute = false;

        // Optimización: Solo validamos si se tocaron las fechas.
        if ($args->hasChangedField('inicio') || $args->hasChangedField('fin')) {
            $this->validarFechas($evento);
        }

        // RED DE SEGURIDAD 1: Recuperación de Canal Directo
        if ($evento->getChannel() === null && !$evento->isOta()) {
            $this->asegurarCanalDirecto($evento, $args->getObjectManager());
            $needsRecompute = true;
        }

        // RED DE SEGURIDAD 2: Automatización de Confirmación por Pago
        if ($args->hasChangedField('estadoPago')) {
            if ($this->asegurarEstadoConfirmadoPorPago($evento, $args->getObjectManager())) {
                $needsRecompute = true;
            }
        }

        // Propagar mutaciones internas a Doctrine
        if ($needsRecompute) {
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            $meta = $em->getClassMetadata(PmsEventoCalendario::class);
            $uow->recomputeSingleEntityChangeSet($meta, $evento);
        }
    }

    /**
     * Inyecta el Canal Directo por defecto si una reserva manual se quedó sin canal asignado.
     * Previene datos huérfanos en los reportes financieros.
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
     * Regla de Negocio Automatizada: Si el estado de pago pasa a ser distinto de "no pagado",
     * la reserva pasa automáticamente a "Confirmada", SALVO que sea un estado terminal (ej. Cancelada).
     *
     * @return bool True si se realizó una mutación en la entidad, False en caso contrario.
     */
    private function asegurarEstadoConfirmadoPorPago(PmsEventoCalendario $evento, ObjectManager $em): bool
    {
        $estadoPago = $evento->getEstadoPago();
        $estadoActual = $evento->getEstado();

        // Si faltan relaciones maestras, abortamos la regla (prevención de errores)
        if (!$estadoPago || !$estadoActual) {
            return false;
        }

        // 🔥 ARMONÍA CON EL SECURITY LISTENER: Respeto absoluto a los Estados Terminales.
        // Previene excepciones "AccessDenied" si alguien hace un reembolso parcial de una reserva muerta.
        if ($estadoActual->getId() === PmsEventoEstado::CODIGO_CANCELADA) {
            return false;
        }

        if ($estadoPago->getId() !== PmsEventoEstadoPago::ID_SIN_PAGO) {

            // Verificamos que no esté ya confirmada para ahorrar queries y evitar updates redundantes
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
     * Validación Estricta de Espacio-Tiempo.
     * Garantiza que Beds24 y el sistema local nunca reciban reservas con duración de cero o negativa.
     * * @throws LogicException Si la fecha de fin es menor o igual a la de inicio.
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
                    'Se requiere una duración mínima de 1 minuto/noche. Operación abortada.',
                    $id,
                    $unidad,
                    $fin->format('Y-m-d H:i'),
                    $inicio->format('Y-m-d H:i')
                ));
            }
        }
    }
}