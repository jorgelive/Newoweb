<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnitcaracteristica
 *
 * @ORM\Table(name="res_unitcaracteristica")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitcaracteristicaTranslation")
 */
class ReservaUnitcaracteristica implements Translatable
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
     * @Gedmo\Translatable
     * @ORM\Column(type="text")
     */
    private $contenido;

    /**
     * @var \App\Entity\ReservaUnittipocaracteristica
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnittipocaracteristica", inversedBy="unitnexos")
     * @ORM\JoinColumn(name="unittipocaracteristica_id", referencedColumnName="id", nullable=false)
     */
    protected $unittipocaracteristica;

    /**
     * @var \App\Entity\ReservaUnit
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnit", inversedBy="unitcaracteristicas")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false)
     */
    protected $unit;

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
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getUnittipocaracteristica()->getNombre() . ' : ' . $this->getContenido();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContenido(): ?string
    {
        return $this->contenido;
    }

    public function setContenido(string $contenido): self
    {
        $this->contenido = $contenido;

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

    public function getUnittipocaracteristica(): ?ReservaUnittipocaracteristica
    {
        return $this->unittipocaracteristica;
    }

    public function setUnittipocaracteristica(?ReservaUnittipocaracteristica $unittipocaracteristica): self
    {
        $this->unittipocaracteristica = $unittipocaracteristica;

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


}