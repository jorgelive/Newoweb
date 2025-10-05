<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnitnexo
 */
#[ORM\Table(name: 'res_unitnexo')]
#[ORM\Entity]
class ReservaUnitnexo
{

    /**
     * @var int
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255)]
    private $enlace;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private $related;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private $distintivo;

    /**
     * @var \App\Entity\ReservaChannel
     */
    #[ORM\ManyToOne(targetEntity: 'ReservaChannel', inversedBy: 'unitnexos')]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false)]
    protected $channel;

    /**
     * @var \App\Entity\ReservaUnit
     */
    #[ORM\ManyToOne(targetEntity: 'ReservaUnit', inversedBy: 'unitnexos')]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: false)]
    protected $unit;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: 'ReservaReserva', mappedBy: 'unitnexo', cascade: ['persist', 'remove'])]
    private $reservas;

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private $deshabilitado;

    /**
     * @var \DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var \DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    public function __construct() {
        $this->reservas = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getChannel()->getNombre() . ' : ' . $this->getEnlace();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnlace(): ?string
    {
        return $this->enlace;
    }

    public function setEnlace(string $enlace): self
    {
        $this->enlace = $enlace;

        return $this;
    }

    public function getRelated(): ?string
    {
        return $this->related;
    }

    public function setRelated(?string $related): self
    {
        $this->related = $related;

        return $this;
    }

    public function getDistintivo(): ?string
    {
        return $this->distintivo;
    }

    public function setDistintivo(?string $distintivo): self
    {
        $this->distintivo = $distintivo;

        return $this;
    }

    public function isDeshabilitado(): ?bool
    {
        return $this->deshabilitado;
    }

    public function setDeshabilitado(bool $deshabilitado): self
    {
        $this->deshabilitado = $deshabilitado;

        return $this;
    }

    public function getCreado(): ?\DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(\DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?\DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(\DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getChannel(): ?ReservaChannel
    {
        return $this->channel;
    }

    public function setChannel(?ReservaChannel $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getUnit(): ?ReservaUnit
    {
        return $this->unit;
    }

    public function setUnit(?ReservaUnit $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    /**
     * @return Collection<int, ReservaReserva>
     */
    public function getReservas(): Collection
    {
        return $this->reservas;
    }

    public function addReserva(ReservaReserva $reserva): self
    {
        if(!$this->reservas->contains($reserva)) {
            $this->reservas[] = $reserva;
            $reserva->setUnitnexo($this);
        }

        return $this;
    }

    public function removeReserva(ReservaReserva $reserva): self
    {
        if($this->reservas->removeElement($reserva)) {
            // set the owning side to null (unless already changed)
            if($reserva->getUnitnexo() === $this) {
                $reserva->setUnitnexo(null);
            }
        }

        return $this;
    }
}
