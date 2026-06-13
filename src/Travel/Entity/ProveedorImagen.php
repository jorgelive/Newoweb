<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Gestiona los archivos físicos de la galería de imágenes de un Proveedor.
 * Mapeado nativamente con VichUploader para su administración en EasyAdmin.
 */
#[ApiResource(
    shortName: 'ProveedorImagen',
    operations: [
        new Get(normalizationContext: ['groups' => ['proveedor:item:read']])
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_proveedor_imagen')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class ProveedorImagen
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait; // Para el manejo de tokens e inyección de la URL pública

    #[Groups(['proveedor:item:read'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    #[Groups(['proveedor:item:read'])]
    #[ORM\Column(type: 'boolean')]
    private bool $isPortada = false;

    #[ORM\ManyToOne(targetEntity: Proveedor::class, inversedBy: 'proveedorImagenes')]
    #[ORM\JoinColumn(name: 'proveedor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Proveedor $proveedor = null;

    /* ========================================================================
     * MAPEO DE VICH UPLOADER Y ARCHIVOS FÍSICOS
     * ======================================================================== */

    #[Vich\UploadableField(mapping: 'travel_proveedor_galeria', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[Groups(['proveedor:item:read'])]
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[Groups(['proveedor:item:read'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    /**
     * Propiedad virtual inyectada dinámicamente que expone la ubicación HTTP del recurso.
     */
    #[Groups(['proveedor:item:read'])]
    private ?string $imageUrl = null;

    /**
     * Constructor de ProveedorImagen.
     */
    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * Genera el token de seguridad antes de persistir (Requerido por MediaTrait).
     */
    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    /**
     * Retorna la cadena representativa de la imagen en EasyAdmin.
     * Muestra el nombre de la imagen o su asociación al proveedor.
     *
     * @return string
     */
    public function __toString(): string
    {
        $nombreProveedor = $this->proveedor ? (string) $this->proveedor : 'Proveedor no asignado';
        return sprintf('%s - img - %d', $nombreProveedor, $this->orden);
    }

    /* ========================================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ======================================================================== */

    /**
     * Obtiene el orden de visualización de la imagen.
     */
    public function getOrden(): int
    {
        return $this->orden;
    }

    /**
     * Establece el orden de visualización de la imagen en la galería.
     */
    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    /**
     * Indica si esta imagen es la portada principal del proveedor.
     */
    public function getIsPortada(): bool
    {
        return $this->isPortada;
    }

    /**
     * Establece si esta imagen debe ser tratada como la portada del proveedor.
     */
    public function setIsPortada(bool $isPortada): self
    {
        $this->isPortada = $isPortada;
        return $this;
    }

    /**
     * Obtiene el proveedor al que pertenece esta imagen.
     */
    public function getProveedor(): ?Proveedor
    {
        return $this->proveedor;
    }

    /**
     * Asigna esta imagen a un proveedor específico.
     */
    public function setProveedor(?Proveedor $proveedor): self
    {
        $this->proveedor = $proveedor;
        return $this;
    }

    /**
     * Obtiene la instancia del archivo binario subido (Uso en formulario).
     */
    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    /**
     * Asigna el archivo físico proveniente del formulario de EasyAdmin.
     * Al detectar un recurso muta la marca temporal para disparar los eventos de Doctrine y Vich.
     *
     * @param File|null $imageFile Instancia de archivo binario subido.
     * @return void
     */
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->updatedAt = new DateTimeImmutable();
        }
    }

    /**
     * Obtiene el nombre físico del archivo guardado en el servidor.
     */
    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    /**
     * Establece el nombre físico del archivo guardado en el servidor.
     */
    public function setImageName(?string $imageName): self
    {
        $this->imageName = $imageName;
        return $this;
    }

    /**
     * Obtiene el tamaño del archivo en bytes.
     */
    public function getImageSize(): ?int
    {
        return $this->imageSize;
    }

    /**
     * Establece el tamaño del archivo en bytes.
     */
    public function setImageSize(?int $imageSize): self
    {
        $this->imageSize = $imageSize;
        return $this;
    }

    /**
     * Obtiene la URL pública calculada por el AssetListener.
     */
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    /**
     * Establece la URL pública de la imagen.
     */
    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }
}