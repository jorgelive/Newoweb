<?php

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\MaestroMoneda;
use App\Oweb\Entity\MaestroContacto;
use App\Panel\Trait\ExternalMediaTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item')]
#[Vich\Uploadable]
class PmsGuiaItem
{
    use ExternalMediaTrait;

    public const TIPO_TARJETA = 'card';
    public const TIPO_ALBUM = 'album';
    public const TIPO_VIDEO = 'video';
    public const TIPO_MAPA = 'map';
    public const TIPO_WIFI = 'wifi';
    public const TIPO_CONTACTO = 'contact';
    public const TIPO_SERVICIO = 'service';

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsGuiaSeccion::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsGuiaSeccion $seccion = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $tipo = self::TIPO_TARJETA;

    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es')]
    private array $titulo = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es')]
    private ?array $descripcion = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es')]
    private ?array $labelBoton = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaItemGaleria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $galeria;

    #[ORM\ManyToOne(targetEntity: MaestroContacto::class)]
    private ?MaestroContacto $maestroContacto = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $precio = null;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    private ?MaestroMoneda $moneda = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    public function __construct() { $this->galeria = new ArrayCollection(); }

    public function __toString(): string { return $this->titulo['es'] ?? 'Item ' . $this->id; }

    // Getters y Setters...
    public function getTitulo(): array { return $this->titulo; }
    public function setTitulo(array $t): self { $this->titulo = $t; return $this; }
    public function getDescripcion(): ?array { return $this->descripcion; }
    public function setDescripcion(?array $d): self { $this->descripcion = $d; return $this; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $m): self { $this->metadata = $m; return $this; }
    public function getSeccion(): ?PmsGuiaSeccion { return $this->seccion; }
    public function setSeccion(?PmsGuiaSeccion $s): self { $this->seccion = $s; return $this; }
    public function getGaleria(): Collection { return $this->galeria; }
    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
}