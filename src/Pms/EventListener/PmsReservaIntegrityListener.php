<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Pms\Entity\PmsReserva;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Mantiene la integridad de los datos de la reserva antes de ir a BD.
 * Sanitiza y estandariza los números de teléfono usando libphonenumber de Google.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class PmsReservaIntegrityListener
{
    private PhoneNumberUtil $phoneUtil;

    public function __construct()
    {
        $this->phoneUtil = PhoneNumberUtil::getInstance();
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->sanitizePhones($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->sanitizePhones($args->getObject());
    }

    private function sanitizePhones(object $entity): void
    {
        if (!$entity instanceof PmsReserva) {
            return;
        }

        // Extraemos el ISO del país del huésped (ej: 'PE', 'US'). Fallback 'PE'
        $paisIso = $entity->getPais() ? $entity->getPais()->getId() : 'PE';

        $tel1 = $entity->getTelefono();
        if ($tel1 !== null && $tel1 !== '') {
            $entity->setTelefono($this->cleanPhoneNumber($tel1, $paisIso));
        }

        $tel2 = $entity->getTelefono2();
        if ($tel2 !== null && $tel2 !== '') {
            $entity->setTelefono2($this->cleanPhoneNumber($tel2, $paisIso));
        }
    }

    /**
     * Utiliza libphonenumber para formatear a estándar internacional E.164 (sin el +)
     */
    private function cleanPhoneNumber(string $rawPhone, string $defaultCountryIso): string
    {
        try {
            $numberProto = $this->phoneUtil->parse($rawPhone, strtoupper($defaultCountryIso));

            if ($this->phoneUtil->isPossibleNumber($numberProto)) {
                $formatted = $this->phoneUtil->format($numberProto, PhoneNumberFormat::E164);
                // Retornamos sin el '+' inicial para la BD
                return ltrim($formatted, '+');
            }
        } catch (NumberParseException $e) {
            // Falla silenciosa: si el usuario puso basura pasa al fallback
        }

        // =====================================================================
        // FALLBACK MANUAL
        // =====================================================================
        $clean = preg_replace('/[^0-9]/', '', $rawPhone);

        if (strlen($clean) === 9 && str_starts_with($clean, '9')) {
            return '51' . $clean;
        }

        return $clean;
    }
}