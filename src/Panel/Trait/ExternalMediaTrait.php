<?php

namespace App\Panel\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

trait ExternalMediaTrait
{
    // --- VIDEO EXTERNO (Youtube / Vimeo) ---
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $videoLink = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $provider = null; // 'youtube', 'vimeo'

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $providerId = null;

    // --- IMAGEN LOCAL (Portada / QR / Mapa Estático) ---
    // mapping='guia_images' debe coincidir con tu config de vich_uploader.yaml
    #[Vich\UploadableField(mapping: 'guia_images', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    // --- LOGICA ---

    public function setVideoLink(?string $link): self
    {
        $this->videoLink = $link;
        $this->detectarProveedorVideo($link);
        return $this;
    }

    public function getVideoLink(): ?string { return $this->videoLink; }

    private function detectarProveedorVideo(?string $url): void
    {
        if (!$url) {
            $this->provider = null;
            $this->providerId = null;
            return;
        }

        // Regex simplificados
        if (preg_match('/(youtube\.com|youtu\.be)\/(watch\?v=|embed\/|v\/)?([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $this->provider = 'youtube';
            $this->providerId = end($matches);
        } elseif (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
            $this->provider = 'vimeo';
            $this->providerId = $matches[1];
        } else {
            $this->provider = 'external';
        }
    }

    // --- GETTERS/SETTERS VICH ---
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }
    public function getImageFile(): ?File { return $this->imageFile; }
    public function setImageName(?string $imageName): void { $this->imageName = $imageName; }
    public function getImageName(): ?string { return $this->imageName; }
    public function setImageSize(?int $imageSize): void { $this->imageSize = $imageSize; }
    public function getImageSize(): ?int { return $this->imageSize; }
}