<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
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
    }

    /**
     * Se ejecuta antes de actualizar un registro existente.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        // Optimización: Solo validamos si se tocaron las fechas.
        if ($args->hasChangedField('inicio') || $args->hasChangedField('fin')) {
            $this->validarFechas($evento);
        }

        // RED DE SEGURIDAD: Si EasyAdmin mandó el canal vacío por estar "disabled"
        if ($evento->getChannel() === null && !$evento->isOta()) {
            $this->asegurarCanalDirecto($evento, $args->getObjectManager());

            // 🔥 HACK DOCTRINE: Como estamos en preUpdate, Doctrine ya calculó los cambios.
            // Le decimos al UnitOfWork que vuelva a calcular esta entidad para que guarde el Canal.
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