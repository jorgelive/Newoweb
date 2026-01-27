<?php

declare(strict_types=1);

namespace App\Panel\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Trait ExternalMediaTrait.
 * Gestiona la integración de videos externos y carga de imágenes locales.
 * Utiliza fileUpdatedAt para evitar colisiones con Traits de auditoría global.
 */
trait ExternalMediaTrait
{
    // --- VIDEO EXTERNO (Youtube / Vimeo) ---

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $videoLink = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $provider = null; // 'youtube', 'vimeo', 'external'

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $providerId = null;

    // --- IMAGEN LOCAL (VichUploader) ---

    #[Vich\UploadableField(mapping: 'guia_images', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $imageSize = null;

    /**
     * Campo técnico para disparar la actualización en Doctrine.
     * Renombrado para evitar colisión con TimestampTrait::updatedAt.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fileUpdatedAt = null;

    /*
     * -------------------------------------------------------------------------
     * LÓGICA DE VIDEO
     * -------------------------------------------------------------------------
     */

    public function setVideoLink(?string $link): self
    {
        $this->videoLink = $link;
        $this->detectarProveedorVideo($link);
        return $this;
    }

    public function getVideoLink(): ?string
    {
        return $this->videoLink;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderId(): ?string
    {
        return $this->providerId;
    }

    private function detectarProveedorVideo(?string $url): void
    {
        if (!$url) {
            $this->provider = null;
            $this->providerId = null;
            return;
        }

        if (preg_match('/(youtube\.com|youtu\.be)\/(watch\?v=|embed\/|v\/)?([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $this->provider = 'youtube';
            $this->providerId = end($matches);
        } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
            $this->provider = 'vimeo';
            $this->providerId = $matches[1];
        } else {
            $this->provider = 'external';
            $this->providerId = null;
        }
    }

    /*
     * -------------------------------------------------------------------------
     * LÓGICA DE ARCHIVOS (VICH)
     * -------------------------------------------------------------------------
     */

    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            // "Ensuciamos" la entidad con el nuevo nombre de propiedad
            $this->fileUpdatedAt = new \DateTimeImmutable();
        }
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageName(?string $imageName): void
    {
        $this->imageName = $imageName;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setImageSize(?int $imageSize): void
    {
        $this->imageSize = $imageSize;
    }

    public function getImageSize(): ?int
    {
        return $this->imageSize;
    }

    public function getFileUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->fileUpdatedAt;
    }

    public function setFileUpdatedAt(?\DateTimeImmutable $fileUpdatedAt): self
    {
        $this->fileUpdatedAt = $fileUpdatedAt;
        return $this;
    }
}