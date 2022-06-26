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
class ServicioComponenteitem implements Translatable
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
     * @Gedmo\Translatable
     * @ORM\Column(name="titulo", type="string", length=160, nullable=true)
     */
    private $titulo;

    /**
     * @var bool
     *
     * @ORM\Column(name="nomostrartarifa", type="boolean", options={"default": 0})
     */
    private $nomostrartarifa;

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
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    public function __clone() {
        if ($this->id) {
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


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set nomostrartarifa
     *
     * @param boolean $nomostrartarifa
     *
     * @return ServicioComponenteitem
     */
    public function setNomostrartarifa($nomostrartarifa)
    {
        $this->nomostrartarifa = $nomostrartarifa;

        return $this;
    }

    /**
     * Is nomostrartarifa
     *
     * @return boolean
     */
    public function isNomostrartarifa(): ?bool
    {
        return $this->nomostrartarifa;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ServicioComponenteitem
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;
    
        return $this;
    }

    /**
     * Get creado
     *
     * @return \DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     *
     * @param \DateTime $modificado
     *
     * @return ServicioComponenteitem
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    /**
     * Get modificado
     *
     * @return \DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set componente
     *
     * @param \App\Entity\ServicioComponente $componente
     *
     * @return ServicioComponenteitem
     */
    public function setComponente(\App\Entity\ServicioComponente $componente = null)
    {
        $this->componente = $componente;
    
        return $this;
    }

    /**
     * Get componente
     *
     * @return \App\Entity\ServicioComponente
     */
    public function getComponente()
    {
        return $this->componente;
    }

    /**
     * Get componente
     *
     * @return \App\Entity\Serviciocomponente
     */
    public function getTarifa()
    {
        return $this->componente;
    }



    /**
     * Set titulo
     *
     * @param string $titulo
     *
     * @return ServicioTarifa
     */
    public function setTitulo($titulo)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

}
