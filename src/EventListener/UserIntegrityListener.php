<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Normaliza el móvil del usuario antes de que llegue a la BD.
 *
 * Gemelo de {@see \App\Pms\EventListener\PmsReservaIntegrityListener}, y a propósito: el
 * número del equipo y el del huésped se comparan contra el mismo remitente de WhatsApp, así
 * que tienen que guardarse EN EL MISMO FORMATO —dígitos con código de país, sin `+` ni
 * espacios ni paréntesis—. Si uno se guardara como «+51 987 654 321» y el otro como
 * «51987654321», la búsqueda por número fallaría en silencio y quien escribe entraría como
 * desconocido.
 *
 * Se sanea aquí y no en el CRUD porque hay más puertas de entrada que el formulario
 * (fixtures, comandos, importaciones): el único sitio por el que pasan todas es Doctrine.
 *
 * ⚠️ **País por defecto `PE`**, no el del usuario: `User` no tiene país y el equipo es de
 * Cusco. Un número local de 9 dígitos («987654321») se resuelve como peruano; uno escrito en
 * internacional («+34…») se respeta, porque `PhoneSanitizer` sólo aplica el defecto cuando
 * falta el prefijo.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class UserIntegrityListener
{
    /** El equipo opera en Perú; ver el aviso del docblock de la clase. */
    private const string PAIS_POR_DEFECTO = 'PE';

    public function __construct(
        private readonly PhoneSanitizer $phoneSanitizer
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof User) {
            return;
        }

        $telefono = trim((string) $entity->getTelefono());

        // Vacío se guarda como NULL, no como '': la columna es `unique` y dos cadenas vacías
        // chocarían entre sí, mientras que MySQL admite todos los NULL que haga falta.
        $entity->setTelefono(
            $telefono !== ''
                ? ($this->phoneSanitizer->cleanPhoneNumber($telefono, self::PAIS_POR_DEFECTO) ?: null)
                : null
        );
    }

    /**
     * En `preUpdate` el changeset ya está calculado: tocar la entidad por sus setters no basta
     * y hay que escribir por el objeto del evento (misma trampa documentada en
     * `PmsReservaIntegrityListener`). Se actualiza además la entidad en memoria para que quien
     * siga leyéndola en la misma petición vea el valor ya limpio.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof User || !$args->hasChangedField('telefono')) {
            return;
        }

        $nuevo = trim((string) $args->getNewValue('telefono'));
        $limpio = $nuevo !== ''
            ? ($this->phoneSanitizer->cleanPhoneNumber($nuevo, self::PAIS_POR_DEFECTO) ?: null)
            : null;

        $args->setNewValue('telefono', $limpio);
        $entity->setTelefono($limpio);
    }
}
