<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad PmsGuiaItemGaleria.
 * Gestiona los archivos de imagen vinculados a un elemento de la guía.
 * Implementa UUID y auditoría inmutable mediante traits.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item_galeria')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsGuiaItemGaleria
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /**
     * Relación con el ítem de la guía propietario de la imagen.
     */
    #[ORM\ManyToOne(targetEntity: PmsGuiaItem::class, inversedBy: 'galeria')]
    #[ORM\JoinColumn(
        name: 'item_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsGuiaItem $item = null;

    /**
     * @Vich\UploadableField(mapping="guia_images", fileNameProperty="imageName")
     */
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string')]
    private ?string $imageName = null;

    /**
     * Marca de tiempo técnica para forzar la actualización del archivo en VichUploader.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $imageUpdatedAt = null;

    /**
     * Posición de la imagen dentro de la galería del ítem.
     */
    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    /*
     * -------------------------------------------------------------------------
     * LÓGICA DE ARCHIVOS (VichUploader)
     * -------------------------------------------------------------------------
     */

    /**
     * @param File|null $imageFile
     */
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->imageUpdatedAt = new DateTimeImmutable();
        }
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS (Regla 2026-01-14)
     * -------------------------------------------------------------------------
     */

    public function setImageName(?string $imageName): void
    {
        $this->imageName = $imageName;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setItem(?PmsGuiaItem $item): self
    {
        $this->item = $item;
        return $this;
    }

    public function getItem(): ?PmsGuiaItem
    {
        return $this->item;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    public function getImageUpdatedAt(): ?DateTimeInterface
    {
        return $this->imageUpdatedAt;
    }

    public function setImageUpdatedAt(?DateTimeInterface $imageUpdatedAt): self
    {
        $this->imageUpdatedAt = $imageUpdatedAt;
        return $this;
    }

    /**
     * Representación textual.
     */
    public function __toString(): string
    {
        return $this->imageName ?? ('Imagen UUID ' . $this->getId());
    }
}