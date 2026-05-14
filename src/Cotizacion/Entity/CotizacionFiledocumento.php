<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\State\CotizacionFiledocumentoMultipartProcessor;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ApiResource(
    shortName: 'CotizacionFiledocumento',
    operations: [
        new Post(
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'multipart' => ['multipart/form-data']
            ],
            denormalizationContext: ['groups' => ['file:write']],
            processor: CotizacionFiledocumentoMultipartProcessor::class
        ),
        new Delete()
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file_documento')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class CotizacionFiledocumento
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $vencimiento = null;

    // 🔥 Reemplazado por el nuevo Enum dentro del módulo Cotizacion
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 20, enumType: ArchivoTipoEnum::class)]
    private ?ArchivoTipoEnum $tipodocumento = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'filedocumentos')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    /* ======================================================
     * PROPIEDADES DE VICH UPLOADER Y MEDIA TRAIT
     * ====================================================== */
    #[Vich\UploadableField(mapping: 'cotizacion_file_documentos', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    /**
     * Propiedad virtual para exponer la URL pública.
     * Es inyectada dinámicamente por el AssetListener.
     */
    #[Groups(['file:item:read'])]
    private ?string $imageUrl = null;

    public function __construct()
    {
        $this->initializeId();
    }

    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    public function __toString(): string
    {
        $nombre = $this->imageName ?? 'Documento sin archivo';
        if ($this->vencimiento) {
            return sprintf('%s | %s', $this->vencimiento->format('Y-m-d'), $nombre);
        }
        return $nombre;
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getVencimiento(): ?DateTimeInterface { return $this->vencimiento; }
    public function setVencimiento(?DateTimeInterface $vencimiento): self { $this->vencimiento = $vencimiento; return $this; }

    public function getTipodocumento(): ?ArchivoTipoEnum { return $this->tipodocumento; }
    public function setTipodocumento(?ArchivoTipoEnum $tipodocumento): self { $this->tipodocumento = $tipodocumento; return $this; }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getImageFile(): ?File { return $this->imageFile; }
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            // Forzar actualización de la entidad para que Doctrine detecte el cambio y dispare el evento
            $this->updatedAt = new DateTimeImmutable();
        }
    }

    public function getImageName(): ?string { return $this->imageName; }
    public function setImageName(?string $imageName): self { $this->imageName = $imageName; return $this; }

    public function getImageSize(): ?int { return $this->imageSize; }
    public function setImageSize(?int $imageSize): self { $this->imageSize = $imageSize; return $this; }

    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $imageUrl): self { $this->imageUrl = $imageUrl; return $this; }
}