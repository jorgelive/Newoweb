<?php

declare(strict_types=1);

namespace App\Message\Entity\Trait;

use DateTimeImmutable;

/**
 * El veto de las tres colas de envío de mensajes: Beds24, WhatsApp y correo.
 *
 * Un trait y no tres copias porque la regla es UNA —la dice `Message::motivoParaNoEnviar()`— y lo
 * único propio de cada cola es cómo se suelta. Ver `VetoableQueueItemInterface`.
 */
trait VetoPorMensajeCanceladoTrait
{
    public function motivoParaNoEjecutar(): ?string
    {
        return $this->getMessage()?->motivoParaNoEnviar();
    }

    public function marcarVetado(string $motivo, DateTimeImmutable $now): void
    {
        $this->setStatus(self::STATUS_CANCELLED);
        $this->setFailedReason($motivo);
        $this->setLockedAt(null);
        $this->setLockedBy(null);
    }
}
