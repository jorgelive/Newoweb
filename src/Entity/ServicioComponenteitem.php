<?php

namespace App\Entity;

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
     * @ORM\Column(type="string", length=160, nullable=true)
     */
    private $titulo;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $nomostrartarifa;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $nomostrarmodalidadtarifa;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $nomostrarcategoriatour;

    /**
     * @var \App\Entity\ServicioComponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioComponente", inversedBy="componenteitems")
     * @ORM\JoinColumn(name="componente_id", referencedColumnName="id", nullable=false)
     */
    protected $componente;

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

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
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
