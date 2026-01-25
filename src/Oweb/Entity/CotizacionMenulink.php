<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(
    name: 'cot_menulink',
    indexes: [new ORM\Index(columns: ['menu_id', 'posicion'], name: 'idx_menu_pos')],
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_menu_cot', columns: ['menu_id', 'cotizacion_id'])]
)]
#[ORM\Entity]
class CotizacionMenulink
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue('AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CotizacionMenu::class, inversedBy: 'menulinks')]
    #[ORM\JoinColumn(name: 'menu_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionMenu $menu = null;

    #[ORM\ManyToOne(targetEntity: CotizacionCotizacion::class, inversedBy: 'menulinks')]
    #[ORM\JoinColumn(name: 'cotizacion_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotizacion $cotizacion = null;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $posicion = 1;

    // Timestampable NO NULL
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function getId(): ?int { return $this->id; }

    public function getMenu(): ?CotizacionMenu { return $this->menu; }
    public function setMenu(?CotizacionMenu $menu): self { $this->menu = $menu; return $this; }

    public function getCotizacion(): ?CotizacionCotizacion { return $this->cotizacion; }
    public function setCotizacion(?CotizacionCotizacion $cotizacion): self { $this->cotizacion = $cotizacion; return $this; }

    public function getPosicion(): int { return $this->posicion; }
    public function setPosicion(int $posicion): self { $this->posicion = $posicion; return $this; }

    public function getCreado(): ?DateTimeInterface { return $this->creado; }
    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?DateTimeInterface { return $this->modificado; }
    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
}
