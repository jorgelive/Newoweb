<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\HttpFoundation\File\File;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item_galeria')]
#[Vich\Uploadable]
class PmsGuiaItemGaleria
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsGuiaItem::class, inversedBy: 'galeria')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PmsGuiaItem $item = null;

    #[Vich\UploadableField(mapping: 'guia_images', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string')]
    private ?string $imageName = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $imageUpdatedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    public function setImageFile(?File $imageFile = null): void {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) { $this->imageUpdatedAt = new \DateTimeImmutable(); }
    }
    public function getImageFile(): ?File { return $this->imageFile; }
    public function setImageName(?string $imageName): void { $this->imageName = $imageName; }
    public function getImageName(): ?string { return $this->imageName; }
    public function setItem(?PmsGuiaItem $item): self { $this->item = $item; return $this; }
    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $o): self { $this->orden = $o; return $this; }
    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
}