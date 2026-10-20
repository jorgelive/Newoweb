<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Maestro\MaestroIdioma;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsReserva;
use App\Service\Idioma\IdiomaDeTrabajo;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Ninguna conversación ni reserva se guarda en un idioma que no trabajamos: se guarda en inglés.
 * Ver {@see IdiomaDeTrabajo}.
 *
 * Es la RED, no la regla: hay diecisiete sitios que asignan idioma (la lectura de Beds24, tres
 * detecciones por mensaje, la fusión de hilos, crear y modificar reserva, el panel…) y el siguiente
 * que se escriba se olvidaría de la regla. Las fuentes automáticas la aplican ya en origen, para que
 * la lectura de Beds24 de cada cinco minutos no reescriba la reserva con el mismo valor.
 *
 * ⚠️ Si lo que llega es un idioma de prioridad 0 y lo que había ya era inglés, el changeset queda
 * `[en → en]` pero Doctrine ejecuta el `UPDATE` igual (con el mismo valor) y dispara los
 * `postUpdate`, Mercure incluido. Inofensivo, y hoy sólo puede pasar desde un formulario: las
 * fuentes automáticas normalizan y comparan antes de asignar, y los selectores del panel ya sólo
 * ofrecen idiomas de trabajo.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final readonly class IdiomaDeTrabajoListener
{
    public function __construct(
        private IdiomaDeTrabajo $idiomas,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entidad = $args->getObject();

        if (!$entidad instanceof MessageConversation && !$entidad instanceof PmsReserva) {
            return;
        }

        $idioma = $entidad->getIdioma();

        if ($idioma instanceof MaestroIdioma && !$idioma->esDeTrabajo()) {
            $entidad->setIdioma($this->idiomas->para($idioma));
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entidad = $args->getObject();

        if ((!$entidad instanceof MessageConversation && !$entidad instanceof PmsReserva) || !$args->hasChangedField('idioma')) {
            return;
        }

        $nuevo = $args->getNewValue('idioma');

        if (!$nuevo instanceof MaestroIdioma || $nuevo->esDeTrabajo()) {
            return;
        }

        $ingles = $this->idiomas->para($nuevo);
        // Las dos cosas: el valor que va a la base y el objeto, para que lo que lea el resto de
        // esta petición no sea el idioma que acaba de descartarse.
        $args->setNewValue('idioma', $ingles);
        $entidad->setIdioma($ingles);
    }
}
