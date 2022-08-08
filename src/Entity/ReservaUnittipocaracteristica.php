<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnittipocaracteristica
 *
 * @ORM\Table(name="res_unittipocaracteristica")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnittipocaracteristicaTranslation")
 */
class ReservaUnittipocaracteristica implements Translatable
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
     * @ORM\Column(name="nombre", type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(name="titulo", type="string", length=100, nullable=false)
     */
    private $titulo;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaUnitcaracteristica", mappedBy="unittipocaracteristica", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $unitcaracteristicas;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     *r
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

    public function __construct() {
        $this->reservas = new ArrayCollection();
        $this->unitcaracteristicas = new ArrayCollection();
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

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = $titulo;

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
     * @return Collection<int, ReservaUnitcaracteristica>
     */
    public function getUnitcaracteristicas(): Collection
    {
        return $this->unitcaracteristicas;
    }

    public function addUnitcaracteristica(ReservaUnitcaracteristica $unitcaracteristica): self
    {
        if (!$this->unitcaracteristicas->contains($unitcaracteristica)) {
            $this->unitcaracteristicas[] = $unitcaracteristica;
            $unitcaracteristica->setUnittipocaracteristica($this);
        }

        return $this;
    }

    public function removeUnitcaracteristica(ReservaUnitcaracteristica $unitcaracteristica): self
    {
        if ($this->unitcaracteristicas->removeElement($unitcaracteristica)) {
            // set the owning side to null (unless already changed)
            if ($unitcaracteristica->getUnittipocaracteristica() === $this) {
                $unitcaracteristica->setUnittipocaracteristica(null);
            }
        }

        return $this;
    }



}
