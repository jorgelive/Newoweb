<?php

namespace App\Pms\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_has_seccion')]
/** * Unicidad: No repetir la misma sección en la misma guía
 */
#[ORM\UniqueConstraint(name: 'uniq_guia_seccion', columns: ['guia_id', 'seccion_id'])]
class PmsGuiaHasSeccion
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PmsGuia::class, inversedBy: 'guiaHasSecciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PmsGuia $guia = null;

    #[ORM\ManyToOne(targetEntity: PmsGuiaSeccion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PmsGuiaSeccion $seccion = null;

    #[ORM\Column(type: 'integer')]
    private int $orden = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    public function getId(): ?int { return $this->id; }
    public function getGuia(): ?PmsGuia { return $this->guia; }
    public function setGuia(?PmsGuia $g): self { $this->guia = $g; return $this; }
    public function getSeccion(): ?PmsGuiaSeccion { return $this->seccion; }
    public function setSeccion(?PmsGuiaSeccion $s): self { $this->seccion = $s; return $this; }
    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $o): self { $this->orden = $o; return $this; }
    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $a): self { $this->activo = $a; return $this; }
    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
}