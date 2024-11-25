<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;


/**
 * ServicioComponenteitem
 *
 * @ORM\Table(name="ser_componenteitem")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioComponenteitemTranslation")
 */
class ServicioComponenteitem
{

    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToMany(targetEntity="ServicioComponenteitemTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected Collection $translations;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=160, nullable=true)
     */
    private ?string $titulo = null;

    /**
     * @ORM\Column(type="string", columnDefinition= "varchar(160) AS (titulo) VIRTUAL NULL", generated="ALWAYS", insertable=false, updatable=false )
     */
    private ?string $titulooriginal = null;

    /**
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private ?bool $nomostrartarifa = false;

    /**
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private ?bool $nomostrarmodalidadtarifa = false;

    /**
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private ?bool $nomostrarcategoriatour =  false;

    /**
     * @ORM\ManyToOne(targetEntity="ServicioComponente", inversedBy="componenteitems")
     * @ORM\JoinColumn(name="componente_id", referencedColumnName="id", nullable=false)
     */
    protected ?ServicioComponente $componente;

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

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
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

    public function addTranslation(ServicioComponenteitemTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf('%s', $this->getTitulo()) ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;

        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function getTitulooriginal(): ?string
    {
        return $this->titulooriginal;
    }

    public function setNomostrartarifa(?bool $nomostrartarifa): self
    {
        $this->nomostrartarifa = $nomostrartarifa;

        return $this;
    }

    public function isNomostrartarifa(): ?bool
    {
        return $this->nomostrartarifa;
    }

    public function setNomostrarmodalidadtarifa(?bool $nomostrarmodalidadtarifa): self
    {
        $this->nomostrarmodalidadtarifa = $nomostrarmodalidadtarifa;

        return $this;
    }

    public function isNomostrarmodalidadtarifa(): ?bool
    {
        return $this->nomostrarmodalidadtarifa;
    }

    public function setNomostrarcategoriatour(?bool $nomostrarcategoriatour): self
    {
        $this->nomostrarcategoriatour = $nomostrarcategoriatour;

        return $this;
    }

    public function isNomostrarcategoriatour(): ?bool
    {
        return $this->nomostrarcategoriatour;
    }

    public function setCreado(?\DateTime $creado): self
    {
        $this->creado = $creado;
    
        return $this;
    }

    public function getCreado(): \DateTime
    {
        return $this->creado;
    }

    public function setModificado(?\DateTime $modificado): self
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    public function getModificado(): ?\DateTime
    {
        return $this->modificado;
    }

    public function setComponente(?ServicioComponente $componente = null): self
    {
        $this->componente = $componente;
    
        return $this;
    }

    public function getComponente(): ?ServicioComponente
    {
        return $this->componente;
    }

}
