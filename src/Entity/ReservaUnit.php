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

    public const DB_VALOR_N1 = 1;
    public const DB_VALOR_N2 = 2;
    public const DB_VALOR_N3 = 3;
    public const DB_VALOR_N4 = 4;
    public const DB_VALOR_N5 = 5;
    public const DB_VALOR_N6 = 6;
    public const DB_VALOR_N7 = 7;


    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToMany(targetEntity="ReservaUnitTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected Collection $translations;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $nombre = null;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private ?string $descripcion = null;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private ?string $referencia = null;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaEstablecimiento", inversedBy="units")
     * @ORM\JoinColumn(name="establecimiento_id", referencedColumnName="id", nullable=false)
     */
    protected ?ReservaEstablecimiento $establecimiento;

    /**
     * @ORM\OneToMany(targetEntity="ReservaReserva", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private Collection $reservas;

    /**
     * @ORM\OneToMany(targetEntity="ReservaUnitnexo", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private Collection $unitnexos;

    /**
     * @ORM\OneToMany(targetEntity="ReservaUnitcaracteristica", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private Collection $unitcaracteristicas;

    /**
     * @ORM\OneToMany(targetEntity="ReservaUnitmedio", mappedBy="unit", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private Collection $unitmedios;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $creado;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $modificado;

    private ?string $locale = null;

    public function __construct() {
        $this->reservas = new ArrayCollection();
        $this->unitnexos = new ArrayCollection();
        $this->unitcaracteristicas = new ArrayCollection();
        $this->unitmedios = new ArrayCollection();
        $this->translations = new ArrayCollection();

    }

    public function __toString(): string
    {
        return sprintf('%s %s',$this->getNombre(), $this->getEstablecimiento()->getNombre());
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations()
    {
        return $this->translations;
    }

    public function addTranslation(ReservaUnitTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
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

    public function getUnitmedios(): Collection
    {
        return $this->unitmedios;
    }

    public function addUnitmedio(ReservaUnitmedio $unitmedio): self
    {
        $unitmedio->setUnit($this);

        $this->unitmedios[] = $unitmedio;

        return $this;
    }

    public function removeUnitmedio(Reservaunitmedio $unitmedio): self
    {
        if($this->unitmedios->removeElement($unitmedio)) {

            if($unitmedio->getUnit() === $this) {
               $unitmedio->setUnit(null);
            }
        }

        return $this;
    }

}
