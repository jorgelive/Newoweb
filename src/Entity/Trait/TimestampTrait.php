<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Trait TimestampTrait
 * Gestiona createdAt y updatedAt usando DateTimeImmutable.
 */
trait TimestampTrait
{
    /**
     * @var \DateTimeImmutable|null
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['timestamp:read'])]
    protected ?\DateTimeImmutable $createdAt = null;

    /**
     * @var \DateTimeImmutable|null
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['timestamp:read'])]
    protected ?\DateTimeImmutable $updatedAt = null;

    /**
     * @return \DateTimeImmutable|null
     */
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param \DateTimeImmutable|null $createdAt
     * @return $this
     */
    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param \DateTimeImmutable|null $updatedAt
     * @return $this
     */
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Restablece las fechas de auditoría a su estado inicial.
     * Este método existe para asegurar que, al clonar una entidad, esta sea tratada
     * como un registro completamente nuevo por el sistema de auditoría (Gedmo),
     * en lugar de heredar la historia temporal del objeto original.
     *
     * Ejemplo de uso:
     * public function __clone() {
     * $this->resetTimestamps();
     * }
     */
    public function resetTimestamps(): void
    {
        $this->createdAt = null;
        $this->updatedAt = null;
    }
}