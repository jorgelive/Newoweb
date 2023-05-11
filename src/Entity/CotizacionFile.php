<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionFile
 *
 * @ORM\Table(name="cot_file")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 */
class CotizacionFile
{

    /**
     * Para el calendario
     * @var string
     *
     */
    private $color;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=20)
     */
    private $token;

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
     * @var \App\Entity\MaestroPais
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroPais")
     * @ORM\JoinColumn(name="pais_id", referencedColumnName="id", nullable=false)
     */
    protected $pais;

    /**
     * @var \App\Entity\MaestroIdioma
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroIdioma")
     * @ORM\JoinColumn(name="idioma_id", referencedColumnName="id", nullable=false)
     */
    protected $idioma;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCotizacion", mappedBy="file", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $cotizaciones;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionFiledocumento", mappedBy="file", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private $filedocumentos;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionFilepasajero", mappedBy="file", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $filepasajeros;

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
        $this->cotizaciones = new ArrayCollection();
        $this->filepasajeros = new ArrayCollection();
        $this->filedocumentos = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    /**
     * @ORM\PostLoad
     */
    public function init()
    {
        $this->color = sprintf("#%02x%02x%02x", mt_rand(0x22, 0xaa), mt_rand(0x22, 0xaa), mt_rand(0x22, 0xaa));
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
     * Get color
     *
     * @return string
     */
    public function getColor(){
        return $this->color;
    }

    /**
     * Set token
     *
     * @param string $token
     *
     * @return CotizacionFile
     */
    public function setToken($token)
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Get token
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     *
     * @return CotizacionFile
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
     * Set pais
     *
     * @param \App\Entity\MaestroPais $pais
     *
     * @return CotizacionFile
     */
    public function setPais(\App\Entity\MaestroPais $pais = null)
    {
        $this->pais = $pais;
    
        return $this;
    }

    /**
     * Get pais
     *
     * @return \App\Entity\MaestroPais
     */
    public function getPais()
    {
        return $this->pais;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionFile
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
     * @return CotizacionFile
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
     * @return CotizacionFile
     */
    public function addCotizacion(\App\Entity\CotizacionCotizacion $cotizacion)
    {
        $cotizacion->setFile($this);

        $this->cotizaciones[] = $cotizacion;
    
        return $this;
    }


    /**
     * Add cotizacione por inflector ingles
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     *
     * @return CotizacionFile
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
     * Set idioma.
     *
     * @param \App\Entity\MaestroIdioma|null $idioma
     *
     * @return CotizacionFile
     */
    public function setIdioma(\App\Entity\MaestroIdioma $idioma = null)
    {
        $this->idioma = $idioma;
    
        return $this;
    }

    /**
     * Get idioma.
     *
     * @return \App\Entity\MaestroIdioma|null
     */
    public function getIdioma()
    {
        return $this->idioma;
    }

    /**
     * Add filepasajero.
     *
     * @param \App\Entity\CotizacionFilepasajero $filepasajero
     *
     * @return CotizacionFile
     */
    public function addFilepasajero(\App\Entity\CotizacionFilepasajero $filepasajero)
    {
        $filepasajero->setFile($this);

        $this->filepasajeros[] = $filepasajero;
    
        return $this;
    }

    /**
     * Remove filepasajero.
     *
     * @param \App\Entity\CotizacionFilepasajero $filepasajero
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeFilepasajero(\App\Entity\CotizacionFilepasajero $filepasajero)
    {
        return $this->filepasajeros->removeElement($filepasajero);
    }

    /**
     * Get filepasajeros.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getFilepasajeros()
    {
        return $this->filepasajeros;
    }

    /**
     * Add filedocumento.
     *
     * @param \App\Entity\CotizacionFiledocumento $filedocumento
     *
     * @return CotizacionFile
     */
    public function addFiledocumento(\App\Entity\CotizacionFiledocumento $filedocumento)
    {
        $filedocumento->setFile($this);

        $this->filedocumentos[] = $filedocumento;
    
        return $this;
    }

    /**
     * Remove filedocumento.
     *
     * @param \App\Entity\CotizacionFiledocumento $filedocumento
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeFiledocumento(\App\Entity\CotizacionFiledocumento $filedocumento)
    {
        return $this->filedocumentos->removeElement($filedocumento);
    }

    /**
     * Get filedocumentos.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getFiledocumentos()
    {
        return $this->filedocumentos;
    }

}
