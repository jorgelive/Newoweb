<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaEstablecimiento
 *
 * @ORM\Table(name="res_establecimiento")
 * @ORM\Entity
 */
class ReservaEstablecimiento
{

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
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
     * @var string
     *
     * @ORM\Column(type="string", length=255)
     */
    private $direccion;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=5)
     */
    private $checkin;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=5)
     */
    private $checkout;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnit", mappedBy="establecimiento", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $units;

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
        $this->units = new ArrayCollection();

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

    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    public function setDireccion(string $direccion): self
    {
        $this->direccion = $direccion;

        return $this;
    }

    public function getCheckin(): ?string
    {
        return $this->checkin;
    }

    public function setCheckin(string $checkin): self
    {
        $this->checkin = $checkin;

        return $this;
    }

    public function getCheckout(): ?string
    {
        return $this->checkout;
    }

    public function setCheckout(string $checkout): self
    {
        $this->checkout = $checkout;

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

    /**
     * @return Collection<int, ReservaUnit>
     */
    public function getUnits(): Collection
    {
        return $this->units;
    }

    public function addUnit(ReservaUnit $unit): self
    {
        if (!$this->units->contains($unit)) {
            $this->units[] = $unit;
            $unit->setEstablecimiento($this);
        }

        return $this;
    }

    public function removeUnit(ReservaUnit $unit): self
    {
        if ($this->units->removeElement($unit)) {
            // set the owning side to null (unless already changed)
            if ($unit->getEstablecimiento() === $this) {
                $unit->setEstablecimiento(null);
            }
        }

        return $this;
    }
    
}
