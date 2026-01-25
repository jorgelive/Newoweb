<?php

namespace App\Oweb\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;


/**
 * ServicioItidiaarchivo
 */
#[ORM\Table(name: 'ser_itidiaarchivo')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class ServicioItidiaarchivo
{

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServicioItinerariodia::class, inversedBy: 'itidiaarchivos')]
    #[ORM\JoinColumn(name: 'itinerariodia_id', referencedColumnName: 'id', nullable: false)]
    protected ?ServicioItinerariodia $itinerariodia;

    #[ORM\ManyToOne(targetEntity: MaestroMedio::class)]
    #[ORM\JoinColumn(name: 'medio_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMedio $medio;

    #[ORM\Column(name: 'prioridad', type: 'integer', nullable: true)]
    private ?int $prioridad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private ?bool $portada = false;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $creado;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $modificado;

    public function __toString(): string
    {
        return $this->getMedio()->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setItinerariodia(?ServicioItinerariodia $itinerariodia):  self
    {
        $this->itinerariodia = $itinerariodia;

        return $this;
    }

    public function getItinerariodia(): ?ServicioItinerariodia
    {
        return $this->itinerariodia;
    }

    public function setCreado(?DateTime $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getCreado(): ?DateTime
    {
        return $this->creado;
    }

    public function setModificado(?DateTime $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }


    public function getModificado(): ?DateTime
    {
        return $this->modificado;
    }

    public function setPrioridad(?int $prioridad): self
    {
        $this->prioridad = $prioridad;
    
        return $this;
    }

    public function getPrioridad(): ?int
    {
        return $this->prioridad;
    }

    public function setMedio(?MaestroMedio $medio): self
    {
        $this->medio = $medio;
    
        return $this;
    }

    public function getMedio(): ?MaestroMedio
    {
        return $this->medio;
    }

    public function setPortada(?bool $portada): self
    {
        $this->portada = $portada;

        return $this;
    }

    public function isPortada(): ?bool
    {
        return $this->portada;
    }
}
