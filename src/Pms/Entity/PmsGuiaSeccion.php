<?php

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_seccion')]
class PmsGuiaSeccion
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es')]
    private array $titulo = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $icono = 'info';

    /**
     * Si es TRUE, esta sección aparecerá disponible para todas las guías
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esComun = false;

    #[ORM\OneToMany(mappedBy: 'seccion', targetEntity: PmsGuiaItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $items;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    public function __construct() {
        $this->items = new ArrayCollection();
        $this->titulo = [];
    }

    public function __toString(): string {
        $prefix = $this->esComun ? '[COMÚN] ' : '';
        return $prefix . ($this->titulo['es'] ?? 'Sección ' . $this->id);
    }

    public function getId(): ?int { return $this->id; }
    public function getTitulo(): array { return $this->titulo; }
    public function setTitulo(array $t): self { $this->titulo = $t; return $this; }
    public function getIcono(): ?string { return $this->icono; }
    public function setIcono(?string $i): self { $this->icono = $i; return $this; }
    public function isEsComun(): bool { return $this->esComun; }
    public function setEsComun(bool $comun): self { $this->esComun = $comun; return $this; }

    /** @return Collection<int, PmsGuiaItem> */
    public function getItems(): Collection { return $this->items; }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
}