<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad física de fotos para el storytelling manejada con VichUploader.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento_imagen')]
#[Vich\Uploadable]
class TravelSegmentoImagen
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: TravelSegmento::class, inversedBy: 'imagenes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelSegmento $segmento = null;

    #[Vich\UploadableField(mapping: 'travel_segmento_imagenes', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    public function __construct()
    {
        $this->initializeId();
    }

    public function getSegmento(): ?TravelSegmento
    {
        return $this->segmento;
    }

    public function setSegmento(?TravelSegmento $segmento): self
    {
        $this->segmento = $segmento;
        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    /**
     * Requerido por VichUploader para forzar la actualización en la base de datos al cambiar archivo.
     */
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->updatedAt = new DateTimeImmutable();
        }
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setImageName(?string $imageName): self
    {
        $this->imageName = $imageName;
        return $this;
    }

    public function getImageSize(): ?int
    {
        return $this->imageSize;
    }

    public function setImageSize(?int $imageSize): self
    {
        $this->imageSize = $imageSize;
        return $this;
    }


}
