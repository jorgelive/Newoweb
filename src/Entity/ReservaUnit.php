<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnit
 *
 * @ORM\Table(name="res_unit")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitTranslation")
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
     * @var ArrayCollection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private $descripcion;

    /**
     * @var string
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private $referencia;

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
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitcaracteristica", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $unitcaracteristicas;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitmedio", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private $unitmedios;

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

    /**
     * @Gedmo\Locale
     */
    private $locale;

    public function __construct() {
        $this->reservas = new ArrayCollection();
        $this->unitnexos = new ArrayCollection();
        $this->unitcaracteristicas = new ArrayCollection();
        $this->unitmedios = new ArrayCollection();
        $this->translations = new ArrayCollection();

    }

    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf('%s %s',$this->getNombre(), $this->getEstablecimiento()->getNombre());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResumen(): ?string
    {
        return sprintf('%s %s', $this->getNombre(), $this->getEstablecimiento()->getNombre());
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

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(string $descripcion): self
    {
        $this->descripcion = $descripcion;

        return $this;
    }


    public function getReferencia(): ?string
    {
        return $this->referencia;
    }

    public function setReferencia(string $referencia): self
    {
        $this->referencia = $referencia;

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
        if(!$this->reservas->contains($reserva)) {
            $this->reservas[] = $reserva;
            $reserva->setUnit($this);
        }

        return $this;
    }

    public function removeReserva(ReservaReserva $reserva): self
    {
        if($this->reservas->removeElement($reserva)) {
            // set the owning side to null (unless already changed)
            if($reserva->getUnit() === $this) {
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
        if(!$this->unitnexos->contains($unitnexo)) {
            $this->unitnexos[] = $unitnexo;
            $unitnexo->setUnit($this);
        }

        return $this;
    }

    public function removeUnitnexo(ReservaUnitnexo $unitnexo): self
    {
        if($this->unitnexos->removeElement($unitnexo)) {
            // set the owning side to null (unless already changed)
            if($unitnexo->getUnit() === $this) {
                $unitnexo->setUnit(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ReservaUnitcaracteristica>
     */
    public function getUnitcaracteristicas(): Collection
    {
        return $this->unitcaracteristicas;
    }

    public function addUnitcaracteristica(ReservaUnitcaracteristica $unitcaracteristica): self
    {
        if(!$this->unitcaracteristicas->contains($unitcaracteristica)) {
            $this->unitcaracteristicas[] = $unitcaracteristica;
            $unitcaracteristica->setUnit($this);
        }

        return $this;
    }

    public function removeUnitcaracteristica(ReservaUnitcaracteristica $unitcaracteristica): self
    {
        if($this->unitcaracteristicas->removeElement($unitcaracteristica)) {
            // set the owning side to null (unless already changed)
            if($unitcaracteristica->getUnit() === $this) {
                $unitcaracteristica->setUnit(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ReservaUnitmedio>
     */
    public function getUnitmedios(): Collection
    {
        return $this->unitmedios;
    }


    public function addUnitmedio(\App\Entity\ReservaUnitmedio $unitmedio): self
    {
        $unitmedio->setUnit($this);

        $this->unitmedios[] = $unitmedio;

        return $this;
    }

    public function removeUnitmedio(\App\Entity\Reservaunitmedio $unitmedio): self
    {

        if($this->unitmedios->removeElement($unitmedio)) {
            // set the owning side to null (unless already changed)
            if($unitmedio->getUnit() === $this) {
                $unitmedio->setUnit(null);
            }
        }

        return $this;
    }


}
