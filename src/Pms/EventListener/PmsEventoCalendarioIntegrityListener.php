<?php
declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use LogicException;

/**
 * Listener de Integridad de Datos.
 * * Objetivo: Validar reglas de negocio estrictas antes de guardar en BD.
 * * Regla Principal: Un evento no puede tener duración cero o negativa.
 * Esto previene el error "invalid departure" en la API de Beds24.
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
    }

    /**
     * Se ejecuta antes de actualizar un registro existente.
     */
    public function preUpdate(PmsEventoCalendario $evento, PreUpdateEventArgs $args): void
    {
        // Solo validamos si se modificaron las fechas para no procesar innecesariamente
        if ($args->hasChangedField('inicio') || $args->hasChangedField('fin')) {
            $this->validarFechas($evento);
        }
    }

    /**
     * Lógica central de validación de fechas.
     * Lanza una excepción si las fechas son incoherentes.
     */
    private function validarFechas(PmsEventoCalendario $evento): void
    {
        $inicio = $evento->getInicio();
        $fin = $evento->getFin();

        // Validamos solo si ambas fechas existen (si son null, la BD o Assert se quejarán por NotNull)
        if ($inicio && $fin) {

            // REGLA: Fin debe ser estrictamente mayor que Inicio.
            // Si son iguales ($fin <= $inicio), es un evento de 0 minutos/noches => INVÁLIDO.
            if ($fin <= $inicio) {

                // Opción A: Bloqueo Estricto (Lanza error 500 explicativo)
                throw new LogicException(sprintf(
                    'ERROR DE INTEGRIDAD: La fecha de fin (%s) no puede ser igual o anterior a la de inicio (%s). ' .
                    'Un evento o bloqueo debe tener una duración mínima (al menos 1 minuto o 1 noche). ' .
                    'Por favor corrija las fechas.',
                    $fin->format('Y-m-d H:i'),
                    $inicio->format('Y-m-d H:i')
                ));

                // Opción B (Comentada): Autocorrección Silenciosa
                // Si prefieres que el sistema lo arregle solo sumando 1 día, descomenta esto y comenta el throw.
                /*
                $nuevoFin = (clone $inicio)->modify('+1 day');
                $evento->setFin($nuevoFin);
                */
            }
        }
    }
}