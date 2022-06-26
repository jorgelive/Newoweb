<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnit
 *
 * @ORM\Table(name="res_unit")
 * @ORM\Entity
 */
class ReservaUnit
{

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @var \App\Entity\ReservaEstablecimiento
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaEstablecimiento", inversedBy="unites")
     * @ORM\JoinColumn(name="establecimiento_id", referencedColumnName="id", nullable=false)
     */
    protected $establecimiento;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaReserva", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $reservas;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitnexo", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $unitnexos;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
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
        return $this->getNombre() . ' ' . $this->getEstablecimiento()->getNombre();
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

    public function getEstablecimiento(): ?ReservaEstablecimiento
    {
        return $this->establecimiento;
    }

    public function setEstablecimiento(?ReservaEstablecimiento $establecimiento): self
    {
        $this->establecimiento = $establecimiento;

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
        if (!$this->reservas->contains($reserva)) {
            $this->reservas[] = $reserva;
            $reserva->setUnit($this);
        }

        return $this;
    }

    public function removeReserva(ReservaReserva $reserva): self
    {
        if ($this->reservas->removeElement($reserva)) {
            // set the owning side to null (unless already changed)
            if ($reserva->getUnit() === $this) {
                $reserva->setUnit(null);
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
        if (!$this->unitnexos->contains($unitnexo)) {
            $this->unitnexos[] = $unitnexo;
            $unitnexo->setUnit($this);
        }

        return $this;
    }

    public function removeUnitnexo(ReservaUnitnexo $unitnexo): self
    {
        if ($this->unitnexos->removeElement($unitnexo)) {
            // set the owning side to null (unless already changed)
            if ($unitnexo->getUnit() === $this) {
                $unitnexo->setUnit(null);
            }
        }

        return $this;
    }

    public function addUnitnexoe(ReservaUnitnexo $unitnexoe): self
    {
        if (!$this->unitnexos->contains($unitnexoe)) {
            $this->unitnexos[] = $unitnexoe;
            $unitnexoe->setUnit($this);
        }

        return $this;
    }

    public function removeUnitnexoe(ReservaUnitnexo $unitnexoe): self
    {
        if ($this->unitnexos->removeElement($unitnexoe)) {
            // set the owning side to null (unless already changed)
            if ($unitnexoe->getUnit() === $this) {
                $unitnexoe->setUnit(null);
            }
        }

        return $this;
    }


}
