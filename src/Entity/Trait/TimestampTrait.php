<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Trait TimestampTrait
 * Gestiona createdAt y updatedAt de forma nativa a prueba de fallos.
 */
trait TimestampTrait
{
    /**
     * @var \DateTimeImmutable|null
     */
    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['timestamp:read'])]
    protected ?\DateTimeImmutable $createdAt = null;

    /**
     * @var \DateTimeImmutable|null
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['timestamp:read'])]
    protected ?\DateTimeImmutable $updatedAt = null;

    /**
     * Se ejecuta automáticamente justo antes de hacer el primer INSERT.
     */
    #[ORM\PrePersist]
    public function setTimestampsOnPersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    /**
     * Se ejecuta automáticamente justo antes de hacer un UPDATE.
     */
    #[ORM\PreUpdate]
    public function setTimestampsOnUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();

        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Restablece las fechas de auditoría a su estado inicial.
     * Ideal para operaciones de DEEP CLONE.
     */
    public function resetTimestamps(): void
    {
        $this->createdAt = null;
        $this->updatedAt = null;
    }
}