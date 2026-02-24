<?php
declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use App\Pms\Repository\PmsGuiaItemGaleriaRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: PmsGuiaItemGaleriaRepository::class)]
#[ORM\Table(name: 'pms_guia_item_galeria')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsGuiaItemGaleria
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: PmsGuiaItem::class, inversedBy: 'galeria')]
    #[ORM\JoinColumn(
        name: 'item_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?PmsGuiaItem $item = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $descripcion = [];

    #[Vich\UploadableField(mapping: 'guia_images', fileNameProperty: 'imageName')]
    #[Assert\File(
        maxSize: "5M",
        mimeTypes: ["image/jpeg", "image/png", "image/webp", "application/pdf"],
        mimeTypesMessage: "Formato no válido. Use JPG, PNG, WEBP o PDF."
    )]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $imageUpdatedAt = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\PositiveOrZero]
    private int $orden = 0;

    /**
     * PROPIEDAD VIRTUAL
     */
    private ?string $imageUrl = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    #[Groups(['pax_evento:read'])]
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $url): self { $this->imageUrl = $url; return $this; }

    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->imageUpdatedAt = new DateTimeImmutable();
        }
    }
    public function getImageFile(): ?File { return $this->imageFile; }

    public function getImageName(): ?string { return $this->imageName; }
    public function setImageName(?string $imageName): void { $this->imageName = $imageName; }

    #[Groups(['pax_evento:read'])]
    public function getDescripcion(): array
    {
        return MaestroIdioma::ordenarParaFormulario($this->descripcion ?? []);
    }

    public function setDescripcion(?array $descripcion): self
    {
        $this->descripcion = MaestroIdioma::normalizarParaDB($descripcion ?? []);
        return $this;
    }

    public function getItem(): ?PmsGuiaItem { return $this->item; }
    public function setItem(?PmsGuiaItem $item): self { $this->item = $item; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function __toString(): string { return $this->imageName ?? 'Elemento de Galería'; }
}