<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *   name="res_unit_caracteristica_link",
 *   uniqueConstraints={@ORM\UniqueConstraint(columns={"unit_id","unitcaracteristica_id"})},
 *   indexes={
 *     @ORM\Index(columns={"unit_id"}),
 *     @ORM\Index(columns={"unitcaracteristica_id"}),
 *     @ORM\Index(columns={"prioridad"})
 *   }
 * )
 */
class ReservaUnitCaracteristicaLink
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnit", inversedBy="unitCaracteristicaLinks")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?ReservaUnit $unit = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnitcaracteristica")
     * @ORM\JoinColumn(name="unitcaracteristica_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?ReservaUnitcaracteristica $caracteristica = null;

    /** @ORM\Column(type="integer", nullable=true) */
    private ?int $prioridad = null;

    /** @ORM\Column(type="datetime") */
    private \DateTime $creado;

    /** @ORM\Column(type="datetime") */
    private \DateTime $modificado;

    public function __construct()
    {
        $this->creado = new \DateTime();
        $this->modificado = new \DateTime();
    }

    public function __toString(): string
    {
        $nom = $this->unit ? ($this->unit->getNombre() ?? 'Unit') : 'Unit';
        $c   = $this->caracteristica ? ('#'.$this->caracteristica->getId()) : 'Caracteristica';
        return sprintf('%s ⇄ %s (p:%s)', $nom, $c, $this->prioridad ?? '-');
    }

    public function getId(): ?int { return $this->id; }

    public function getUnit(): ?ReservaUnit { return $this->unit; }
    public function setUnit(?ReservaUnit $unit): self { $this->unit = $unit; return $this; }

    public function getCaracteristica(): ?ReservaUnitcaracteristica { return $this->caracteristica; }
    public function setCaracteristica(?ReservaUnitcaracteristica $c): self { $this->caracteristica = $c; return $this; }

    public function getPrioridad(): ?int { return $this->prioridad; }
    public function setPrioridad(?int $p): self { $this->prioridad = $p; return $this; }

    public function getCreado(): \DateTime { return $this->creado; }
    public function setCreado(\DateTime $d): self { $this->creado = $d; return $this; }

    public function getModificado(): \DateTime { return $this->modificado; }
    public function setModificado(\DateTime $d): self { $this->modificado = $d; return $this; }
}
