<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionCotnota
 *
 * @ORM\Table(name="cot_cotnota")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\CotizacionCotnotaTranslation")
 */
class CotizacionCotnota implements Translatable
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
     * @ORM\Column(name="titulo", type="string", length=100)
     */
    private $titulo;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(name="contenido", type="text")
     */
    private $contenido;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\CotizacionCotizacion", mappedBy="cotnotas", cascade={"persist","remove"})
     * @ORM\JoinTable(name="cotizacion_cotnota",
     *      joinColumns={@ORM\JoinColumn(name="cotnota_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="cotizacion_id", referencedColumnName="id")}
     * )
     */
    private $cotizaciones;

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

    public function __construct() {
        $this->cotizaciones = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set nombre
     *
     * @param string $nombre
     *
     * @return CotizacionCotnota
     */
    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    /**
     * Get nombre
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionCotnota
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
     * @return CotizacionCotnota
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
     * Add cotizacion
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     *
     * @return CotizacionCotnota
     */
    public function addCotizacion(\App\Entity\CotizacionCotizacion $cotizacion)
    {
        $cotizacion->addCotnota($this);

        $this->cotizaciones[] = $cotizacion;
    
        return $this;
    }


    /**
     * Add cotizacione por inflector ingles
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     *
     * @return CotizacionCotnota
     */
    public function addCotizacione(\App\Entity\CotizacionCotizacion $cotizacion){
        return $this->addCotizacion($cotizacion);
    }

    /**
     * Remove cotizacion
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     */
    public function removeCotizacion(\App\Entity\CotizacionCotizacion $cotizacion)
    {
        $this->cotizaciones->removeElement($cotizacion);
    }

    /**
     * Remove cotizacione por inflector ingles
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     */
    public function removeCotizacione(\App\Entity\CotizacionCotizacion $cotizacion)
    {
        $this->removeCotizacion($cotizacion);
    }


    /**
     * Get cotizaciones
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCotizaciones()
    {
        return $this->cotizaciones;
    }


    /**
     * Set titulo.
     *
     * @param string $titulo
     *
     * @return CotizacionCotnota
     */
    public function setTitulo($titulo)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo.
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

    /**
     * Set contenido.
     *
     * @param string $contenido
     *
     * @return CotizacionCotnota
     */
    public function setContenido($contenido)
    {
        $this->contenido = $contenido;
    
        return $this;
    }

    /**
     * Get contenido.
     *
     * @return string
     */
    public function getContenido()
    {
        return $this->contenido;
    }
}
