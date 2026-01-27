<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Trait\ExternalMediaTrait;
use App\Trait\AutoTranslateControlTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad PmsGuiaItem.
 * Representa un bloque de contenido individual dentro de una sección de la guía.
 * Limpiado: Se eliminan campos de precio para simplificar la fase inicial.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsGuiaItem
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /** Trait global para el control de latencia en traducciones */
    use AutoTranslateControlTrait;

    /** Trait para gestión de medios externos */
    use ExternalMediaTrait;

    public const TIPO_TARJETA = 'card';
    public const TIPO_ALBUM = 'album';
    public const TIPO_VIDEO = 'video';
    public const TIPO_MAPA = 'map';
    public const TIPO_WIFI = 'wifi';
    public const TIPO_CONTACTO = 'contact';
    public const TIPO_SERVICIO = 'service';

    /**
     * Relación con la sección contenedora (UUID).
     */
    #[ORM\ManyToOne(targetEntity: PmsGuiaSeccion::class, inversedBy: 'items')]
    #[ORM\JoinColumn(
        name: 'seccion_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16) COMMENT "(DC2Type:uuid)"'
    )]
    private ?PmsGuiaSeccion $seccion = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $tipo = self::TIPO_TARJETA;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /**
     * Título plano multiidioma.
     */
    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private array $titulo = [];

    /**
     * Descripción multiidioma con soporte HTML.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    private ?array $descripcion = [];

    /**
     * Texto del botón de acción multiidioma.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $labelBoton = [];

    /**
     * Configuración técnica adicional del ítem.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    /**
     * @var Collection<int, PmsGuiaItemGaleria>
     */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaItemGaleria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $galeria;

    public function __construct()
    {
        $this->galeria = new ArrayCollection();
        $this->titulo = [];
        $this->descripcion = [];
        $this->labelBoton = [];
    }

    /* ======================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ====================================================== */

    public function getSeccion(): ?PmsGuiaSeccion { return $this->seccion; }
    public function setSeccion(?PmsGuiaSeccion $seccion): self { $this->seccion = $seccion; return $this; }

    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $tipo): self { $this->tipo = $tipo; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function getTitulo(): array { return $this->titulo; }
    public function setTitulo(array $titulo): self { $this->titulo = $titulo; return $this; }

    public function getDescripcion(): ?array { return $this->descripcion; }
    public function setDescripcion(?array $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function getLabelBoton(): ?array { return $this->labelBoton; }
    public function setLabelBoton(?array $labelBoton): self { $this->labelBoton = $labelBoton; return $this; }

    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }

    /** @return Collection<int, PmsGuiaItemGaleria> */
    public function getGaleria(): Collection { return $this->galeria; }

    public function addItemGaleria(PmsGuiaItemGaleria $foto): self
    {
        if (!$this->galeria->contains($foto)) {
            $this->galeria->add($foto);
            $foto->setItem($this);
        }
        return $this;
    }

    public function removeItemGaleria(PmsGuiaItemGaleria $foto): self
    {
        if ($this->galeria->removeElement($foto)) {
            if ($foto->getItem() === $this) $foto->setItem(null);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->titulo['es'] ?? ('Item UUID ' . ($this->getId() ? $this->getId()->toBase32() : 'Nuevo'));
    }
}