<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

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
    protected ?\DateTimeImmutable $createdAt = null;

    /**
     * @var \DateTimeImmutable|null
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
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
}