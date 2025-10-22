<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(
    name: 'res_unit_caracteristica_link',
    indexes: [
        new ORM\Index(name: 'idx_link_unit', columns: ['unit_id']),
        new ORM\Index(name: 'idx_link_car',  columns: ['unitcaracteristica_id']),
        new ORM\Index(name: 'idx_link_prioridad', columns: ['prioridad']),
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_unit_caracteristica',
            columns: ['unit_id', 'unitcaracteristica_id']
        ),
    ]
)]
class ReservaUnitCaracteristicaLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReservaUnit::class, inversedBy: 'unitCaracteristicaLinks')]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ReservaUnit $unit = null;

    #[ORM\ManyToOne(targetEntity: ReservaUnitcaracteristica::class)]
    #[ORM\JoinColumn(name: 'unitcaracteristica_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ReservaUnitcaracteristica $caracteristica = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $prioridad = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        $nom = $this->unit?->getNombre() ?: 'Unit';
        $c   = $this->caracteristica?->getNombre() ?: 'Caracteristica';
        return sprintf('%s ⇄ %s', $nom, $c);
    }

    // --- Getters/Setters ---

    public function getId(): ?int { return $this->id; }

    public function getUnit(): ?ReservaUnit
    { return $this->unit; }
    public function setUnit(?ReservaUnit $unit): self { $this->unit = $unit; return $this; }

    public function getCaracteristica(): ?ReservaUnitcaracteristica
    { return $this->caracteristica; }
    public function setCaracteristica(?ReservaUnitcaracteristica $c): self { $this->caracteristica = $c; return $this; }

    public function getPrioridad(): ?int { return $this->prioridad; }
    public function setPrioridad(?int $p): self { $this->prioridad = $p; return $this; }

    public function getCreado(): ?DateTimeInterface { return $this->creado; }
    public function setCreado(?DateTimeInterface $d): self { $this->creado = $d; return $this; }

    public function getModificado(): ?DateTimeInterface { return $this->modificado; }
    public function setModificado(?DateTimeInterface $d): self { $this->modificado = $d; return $this; }
}
