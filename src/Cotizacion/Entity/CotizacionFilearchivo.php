<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Enum\ArchivoTipoEnum;
use App\Cotizacion\State\CotizacionFilearchivoMultipartProcessor;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ApiResource(
    shortName: 'CotizacionFilearchivo',
    operations: [
        new Post(
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'multipart' => ['multipart/form-data']
            ],
            denormalizationContext: [
                'groups' => ['file:write'],
                'disable_type_enforcement' => true,   // 🔑 multipart manda todo como string
            ],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para subir documentos.',
            processor: CotizacionFilearchivoMultipartProcessor::class
        ),
        new Patch(
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar documentos.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar documentos.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file_archivo')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class CotizacionFilearchivo
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;
    use AutoTranslateControlTrait;

    /**
     * Qué CLASE DE ARCHIVO es: boleto, factura, confirmación de reserva.
     *
     * ⚠️ Se llamaba `tipodocumento`, y ese nombre hizo leer la entidad entera al revés: esto **no
     * es un documento de identidad**. Aquí viven adjuntos —`Vich\Uploadable`, `MediaTrait`, POST
     * multipart—, no el DNI ni el pasaporte de nadie.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(name: 'tipo_archivo', type: 'string', length: 20, enumType: ArchivoTipoEnum::class)]
    private ?ArchivoTipoEnum $tipoArchivo = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'filearchivos')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    /* ======================================================
     * PROPIEDADES DE VICH UPLOADER Y MEDIA TRAIT
     * ====================================================== */
    #[Vich\UploadableField(mapping: 'cotizacion_file_archivos', fileNameProperty: 'imageName', size: 'imageSize')]
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
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    private ?string $imageUrl = null;

    /** @var list<array{language?: string, content?: string|null}>|null */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $nombre = null;

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
        return $this->getNombreTraducido('es') ?? $this->imageName ?? 'Archivo sin nombre';
    }

    public function isSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['file:write'])]
    public function setSobreescribirTraduccion(bool|string|int|null $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = filter_var($sobreescribirTraduccion, FILTER_VALIDATE_BOOLEAN);
        return $this;
    }

    private function getNombreTraducido(string $lang): ?string
    {
        foreach ($this->nombre ?? [] as $item) {
            if (($item['language'] ?? null) === $lang) {
                return $item['content'] ?? null;
            }
        }
        return null;
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getTipoArchivo(): ?ArchivoTipoEnum { return $this->tipoArchivo; }
    public function setTipoArchivo(?ArchivoTipoEnum $tipoArchivo): self { $this->tipoArchivo = $tipoArchivo; return $this; }

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

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getNombre(): array { return $this->nombre; }
    /**
     * @param list<array{language?: string, content?: string|null}> $nombre
     */
    public function setNombre(array $nombre): void { $this->nombre = $nombre; }
}