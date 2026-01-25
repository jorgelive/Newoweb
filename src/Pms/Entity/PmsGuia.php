<?php

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia')]
class PmsGuia
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: PmsUnidad::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?PmsUnidad $unidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = true;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es')]
    private array $titulo = [];

    /**
     * Relación con la tabla de enlace para permitir secciones compartidas
     */
    #[ORM\OneToMany(mappedBy: 'guia', targetEntity: PmsGuiaHasSeccion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $guiaHasSecciones;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $modificado = null;

    public function __construct() {
        $this->guiaHasSecciones = new ArrayCollection();
        $this->titulo = [];
    }

    public function __toString(): string { return $this->unidad?->getNombre() ?? 'Guía'; }

    public function getId(): ?int { return $this->id; }
    public function getUnidad(): ?PmsUnidad { return $this->unidad; }
    public function setUnidad(?PmsUnidad $u): self { $this->unidad = $u; return $this; }
    public function isActivo(): ?bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }
    public function getTitulo(): array { return $this->titulo; }
    public function setTitulo(array $t): self { $this->titulo = $t; return $this; }

    /** @return Collection<int, PmsGuiaHasSeccion> */
    public function getGuiaHasSecciones(): Collection { return $this->guiaHasSecciones; }

    public function addGuiaHasSeccion(PmsGuiaHasSeccion $link): self {
        if (!$this->guiaHasSecciones->contains($link)) {
            $this->guiaHasSecciones->add($link);
            $link->setGuia($this);
        }
        return $this;
    }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
}