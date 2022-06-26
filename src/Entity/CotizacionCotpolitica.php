<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * CotizacionCotpolitica
 *
 * @ORM\Table(name="cot_cotpolitica")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\CotizacionCotpoliticaTranslation")
 */
class CotizacionCotpolitica implements Translatable
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
     * @ORM\Column(name="contenido", type="text")
     */
    private $contenido;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCotizacion", mappedBy="cotpolitica", cascade={"persist","remove"}, orphanRemoval=true)
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
     * @return CotizacionCotpolitica
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
     * @return CotizacionCotpolitica
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
     * @return CotizacionCotpolitica
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
     * @return CotizacionCotpolitica
     */
    public function addCotizacion(\App\Entity\CotizacionCotizacion $cotizacion)
    {
        $cotizacion->setCotpolitica($this);

        $this->cotizaciones[] = $cotizacion;
    
        return $this;
    }


    /**
     * Add cotizacione por inflector ingles
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     *
     * @return CotizacionCotpolitica
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
     * Set contenido.
     *
     * @param string $contenido
     *
     * @return CotizacionCotpolitica
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
