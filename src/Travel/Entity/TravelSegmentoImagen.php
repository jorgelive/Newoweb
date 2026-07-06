<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento_imagen')]
#[Vich\Uploadable]
#[ORM\HasLifecycleCallbacks]
class TravelSegmentoImagen
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;

    #[ORM\ManyToOne(targetEntity: TravelSegmento::class, inversedBy: 'imagenes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelSegmento $segmento = null;

    #[Vich\UploadableField(mapping: 'travel_segmento_imagenes', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    #[Groups(['segmento:item:read', 'segmento:write'])]
    #[ORM\Column(type: 'boolean')]
    private bool $isPortada = false;

    /**
     * Propiedad virtual para exponer la URL completa de la imagen.
     * Es inyectada dinámicamente por el AssetListener de la entidad.
     */
    #[Groups(['segmento:item:read'])]
    private ?string $imageUrl = null;

    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * Callback del ciclo de vida de Doctrine.
     * Genera el token de seguridad necesario para el MediaTrait antes de que la entidad
     * se persista en la base de datos por primera vez.
     */
    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    /**
     * Obtiene el segmento de viaje asociado a la imagen.
     *
     * @return TravelSegmento|null
     */
    public function getSegmento(): ?TravelSegmento
    {
        return $this->segmento;
    }

    /**
     * Establece el segmento de viaje asociado a la imagen.
     *
     * @param TravelSegmento|null $segmento
     * @return self
     */
    public function setSegmento(?TravelSegmento $segmento): self
    {
        $this->segmento = $segmento;
        return $this;
    }

    /**
     * Obtiene el archivo subido en memoria.
     *
     * @return File|null
     */
    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    /**
     * Requerido por VichUploader para forzar la actualización en la base de datos al cambiar archivo.
     *
     * @param File|null $imageFile
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
     *
     * @return string|null
     */
    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    /**
     * Establece el nombre físico del archivo. Usado internamente por VichUploader.
     *
     * @param string|null $imageName
     * @return self
     */
    public function setImageName(?string $imageName): self
    {
        $this->imageName = $imageName;
        return $this;
    }

    /**
     * Obtiene el tamaño de la imagen en bytes.
     *
     * @return int|null
     */
    public function getImageSize(): ?int
    {
        return $this->imageSize;
    }

    /**
     * Establece el tamaño de la imagen en bytes.
     *
     * @param int|null $imageSize
     * @return self
     */
    public function setImageSize(?int $imageSize): self
    {
        $this->imageSize = $imageSize;
        return $this;
    }

    /**
     * Obtiene el orden de visualización de la imagen.
     *
     * @return int
     */
    public function getOrden(): int
    {
        return $this->orden;
    }

    /**
     * Establece el orden de visualización de la imagen.
     * Útil para organizar galerías en el frontend.
     *
     * @param int $orden
     * @return self
     */
    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    /**
     * Indica si esta imagen es la portada principal del segmento.
     *
     * @return bool
     */
    public function getIsPortada(): bool
    {
        return $this->isPortada;
    }

    /**
     * Establece si esta imagen debe ser tratada como la portada del segmento.
     *
     * @param bool $isPortada
     * @return self
     */
    public function setIsPortada(bool $isPortada): self
    {
        $this->isPortada = $isPortada;
        return $this;
    }

    /**
     * Obtiene la URL pública de la imagen (Propiedad Virtual).
     *
     * @return string|null
     */
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    /**
     * Establece la URL pública de la imagen. Inyectado por el AssetListener.
     *
     * @param string|null $imageUrl
     * @return self
     */
    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    /**
     * Representación en texto de la entidad.
     * Facilita la visualización en listados de EasyAdmin, logs o breadcrumbs.
     * Muestra el nombre del segmento asociado, el indicador "img" y su posición.
     *
     * @return string
     */
    public function __toString(): string
    {
        $nombreSegmento = $this->segmento ? (string) $this->segmento : 'Segmento no asignado';

        return sprintf('%s - img - %d', $nombreSegmento, $this->orden);
    }

    /**
     * Nombre administrativo del segmento padre, para mostrar en el listado de imágenes.
     */
    public function getVirtualSegmentoNombre(): string
    {
        return $this->segmento?->getNombreInterno() ?? '—';
    }

    /**
     * Título público en español del segmento padre.
     * Estructura real: [{"language": "es", "content": "..."}, ...]
     */
    public function getVirtualSegmentoTituloEs(): string
    {
        if (!$this->segmento) {
            return '—';
        }

        foreach ($this->segmento->getTitulo() as $entrada) {
            if (($entrada['language'] ?? null) === 'es') {
                return $entrada['content'] ?? '—';
            }
        }

        return '—';
    }
}