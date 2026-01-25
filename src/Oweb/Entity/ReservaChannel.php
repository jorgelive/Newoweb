<?php

namespace App\Oweb\Entity;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * ReservaChannel
 */
#[ORM\Table(name: 'res_channel')]
#[ORM\Entity]
class ReservaChannel
{

    public const DB_VALOR_DIRECTO = 1;
    public const DB_VALOR_AIRBNB = 2;
    public const DB_VALOR_BOOKING = 3;
    public const DB_VALOR_TRIPADVISOR = 4;
    public const DB_VALOR_VRBO = 5;

    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private $nombre;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: ReservaReserva::class, mappedBy: 'channel', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private $reservas;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: ReservaUnitnexo::class, mappedBy: 'channel', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private $unitnexos;

    /**
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var DateTime $modificado
     *r
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    public function __construct() {
        $this->reservas = new ArrayCollection();
        $this->unitnexos = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

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
            $reserva->setChannel($this);
        }

        return $this;
    }

    public function removeReserva(ReservaReserva $reserva): self
    {
        if($this->reservas->removeElement($reserva)) {
            // set the owning side to null (unless already changed)
            if($reserva->getChannel() === $this) {
                $reserva->setChannel(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ReservaUnitnexo>
     */
    public function getUnitnexos(): Collection
    {
        return $this->unitnexos;
    }

    public function addUnitnexo(ReservaUnitnexo $unitnexo): self
    {
        if(!$this->unitnexos->contains($unitnexo)) {
            $this->unitnexos[] = $unitnexo;
            $unitnexo->setChannel($this);
        }

        return $this;
    }

    public function removeUnitnexo(ReservaUnitnexo $unitnexo): self
    {
        if($this->unitnexos->removeElement($unitnexo)) {
            // set the owning side to null (unless already changed)
            if($unitnexo->getChannel() === $this) {
                $unitnexo->setChannel(null);
            }
        }

        return $this;
    }



}
