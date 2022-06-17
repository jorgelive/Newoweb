<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * CotizacionCotizacion
 *
 * @ORM\Table(name="cot_cotizacion")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\CotizacionCotizacionTranslation")
 */
class CotizacionCotizacion implements Translatable
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
     * @ORM\Column(name="token", type="string", length=20)
     */
    private $token;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(name="titulo", type="string", length=255)
     */
    private $titulo;

    /**
     * @var int
     *
     * @ORM\Column(name="numeropasajeros", type="integer")
     */
    private $numeropasajeros;

    /**
     * @var string
     *
     * @ORM\Column(name="comision", type="decimal", precision=5, scale=2, nullable=false)
     */
    private $comision = '20.00';


    /**
     * @var string
     *
     * @ORM\Column(name="adelanto", type="decimal", precision=5, scale=2, nullable=false)
     */
    private $adelanto = '50.00';

    /**
     * @var \App\Entity\CotizacionEstadocotizacion
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionEstadocotizacion")
     * @ORM\JoinColumn(name="estadocotizacion_id", referencedColumnName="id", nullable=false)
     */
    protected $estadocotizacion;

    /**
     * @var \App\Entity\CotizacionFile
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionFile", inversedBy="cotizaciones")
     * @ORM\JoinColumn(name="file_id", referencedColumnName="id", nullable=false)
     */
    protected $file;

    /**
     * @var \App\Entity\CotizacionCotpolitica
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionCotpolitica", inversedBy="cotizaciones")
     * @ORM\JoinColumn(name="cotpolitica_id", referencedColumnName="id", nullable=false)
     */
    protected $cotpolitica;

    /**
     * @var \App\Entity\CotizacionCotnota
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\CotizacionCotnota", inversedBy="cotizaciones")
     * @ORM\JoinTable(name="cotizacion_cotnota",
     *      joinColumns={@ORM\JoinColumn(name="cotizacion_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="cotnota_id", referencedColumnName="id")}
     * )
     */
    protected $cotnotas;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCotservicio", mappedBy="cotizacion", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"fechahorainicio" = "ASC"})
     */
    private $cotservicios;

    /**
     * @var \Date $fecha
     *
     * @ORM\Column(type="date")
     */
    private $fecha;

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
        $this->cotservicios = new ArrayCollection();
        $this->cotnotas = new ArrayCollection();
    }

    public function __clone() {
        if ($this->id) {
            $this->id = null;
            $this->setFecha(new \DateTime('today'));
            $this->setCreado(null);
            $this->setModificado(null);
            $this->setToken(mt_rand());
            $newCotservicios = new ArrayCollection();
            foreach ($this->cotservicios as $cotservicio) {
                $newCotservicio = clone $cotservicio;
                $newCotservicio->setCotizacion($this);
                $newCotservicios->add($newCotservicio);
            }
            $this->cotservicios = $newCotservicios;
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getFile())){
            return $this->getTitulo() ?? sprintf("Id: %s.", $this->getId()) ?? '';
        }

        if($this->getEstadocotizacion()->getId() == 6){
            return sprintf("%s", $this->getTitulo()) ?? sprintf("Id: %s.", $this->getId()) ?? '';

        }else{
            //como es publico retorno el titulo
            return sprintf("%s x%s : %s.", $this->getFile()->getNombre(), $this->getNumeropasajeros(), $this->getTitulo()) ?? sprintf("Id: %s.", $this->getId()) ?? '';
        }
    }

    /**
     * Get nombre
     *
     * @return string
     */
    public function getPrimerCotservicioFecha()
    {
        if($this->getCotservicios()->count() < 1){
            return null;
        }

        return $this->getCotservicios()->first()->getFechaHoraInicio();
    }

    /**
     * Get resumen
     *
     * @return string
     */
    public function getResumen()
    {
        if(empty($this->getFile())){
            return $this->getTitulo() ?? sprintf("Id: %s.", $this->getId()) ?? '';
        }

        return sprintf("%s : %s.", $this->getFile()->getNombre(), $this->getNombre()) ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set token
     *
     * @param string $token
     *
     * @return CotizacionCotizacion
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
     * @return CotizacionCotizacion
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
     * Set numeropasajeros
     *
     * @param integer $numeropasajeros
     *
     * @return CotizacionCotizacion
     */
    public function setNumeropasajeros($numeropasajeros)
    {
        $this->numeropasajeros = $numeropasajeros;
    
        return $this;
    }

    /**
     * Get numeropasajeros
     *
     * @return integer
     */
    public function getNumeropasajeros()
    {
        return $this->numeropasajeros;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionCotizacion
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
     * @return CotizacionCotizacion
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
     * Set estadocotizacion
     *
     * @param \App\Entity\CotizacionEstadocotizacion $estadocotizacion
     *
     * @return CotizacionCotizacion
     */
    public function setEstadocotizacion(\App\Entity\CotizacionEstadocotizacion $estadocotizacion)
    {
        $this->estadocotizacion = $estadocotizacion;
    
        return $this;
    }

    /**
     * Get estadocotizacion
     *
     * @return \App\Entity\CotizacionEstadocotizacion
     */
    public function getEstadocotizacion()
    {
        return $this->estadocotizacion;
    }

    /**
     * Set file
     *
     * @param \App\Entity\CotizacionFile $file
     *
     * @return CotizacionCotizacion
     */
    public function setFile(\App\Entity\CotizacionFile $file)
    {
        $this->file = $file;
    
        return $this;
    }

    /**
     * Get file
     *
     * @return \App\Entity\CotizacionFile
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * Add cotservicio
     *
     * @param \App\Entity\CotizacionCotservicio $cotservicio
     *
     * @return CotizacionCotizacion
     */
    public function addCotservicio(\App\Entity\CotizacionCotservicio $cotservicio)
    {
        $cotservicio->setCotizacion($this);

        $this->cotservicios[] = $cotservicio;
    
        return $this;
    }

    /**
     * Remove cotservicio
     *
     * @param \App\Entity\CotizacionCotservicio $cotservicio
     */
    public function removeCotservicio(\App\Entity\CotizacionCotservicio $cotservicio)
    {
        $this->cotservicios->removeElement($cotservicio);
    }

    /**
     * Get cotservicios
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCotservicios()
    {
        return $this->cotservicios;
    }

    /**
     * Set comision.
     *
     * @param string $comision
     *
     * @return CotizacionCotizacion
     */
    public function setComision($comision)
    {
        $this->comision = $comision;
    
        return $this;
    }

    /**
     * Get comision.
     *
     * @return string
     */
    public function getComision()
    {
        return $this->comision;
    }



    /**
     * Set adelanto.
     *
     * @param string $adelanto
     *
     * @return CotizacionCotizacion
     */
    public function setAdelanto($adelanto)
    {
        $this->adelanto = $adelanto;

        return $this;
    }

    /**
     * Get adelanto.
     *
     * @return string
     */
    public function getAdelanto()
    {
        return $this->adelanto;
    }

    /**
     * Set cotpolitica.
     *
     * @param \App\Entity\CotizacionCotpolitica|null $cotpolitica
     *
     * @return CotizacionCotizacion
     */
    public function setCotpolitica(\App\Entity\CotizacionCotpolitica $cotpolitica = null)
    {
        $this->cotpolitica = $cotpolitica;
    
        return $this;
    }

    /**
     * Get cotpolitica.
     *
     * @return \App\Entity\CotizacionCotpolitica|null
     */
    public function getCotpolitica()
    {
        return $this->cotpolitica;
    }


    /**
     * Add cotnota.
     *
     * @param \App\Entity\CotizacionCotnota $cotnota
     *
     * @return CotizacionCotizacion
     */
    public function addCotnota(\App\Entity\CotizacionCotnota $cotnota)
    {
        //notajg: no setear el componente ni uilizar by_reference = false en el admin en el owner(en que tiene inversed)

        $this->cotnotas[] = $cotnota;
    
        return $this;
    }

    /**
     * Remove cotnota.
     *
     * @param \App\Entity\CotizacionCotnota $cotnota
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeCotnota(\App\Entity\CotizacionCotnota $cotnota)
    {
        return $this->cotnotas->removeElement($cotnota);
    }

    /**
     * Get cotnotas.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCotnotas()
    {
        return $this->cotnotas;
    }

    /**
     * Set titulo.
     *
     * @param string|null $titulo
     *
     * @return CotizacionCotizacion
     */
    public function setTitulo($titulo = null)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo.
     *
     * @return string|null
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

    /**
     * Set fecha.
     *
     * @param date $fecha
     *
     * @return CotizacionCotizacion
     */
    public function setFecha($fecha)
    {

        $this->fecha = $fecha;

        return $this;
    }

    /**
     * Get fecha.
     *
     * @return date|null
     */
    public function getFecha()
    {
        return $this->fecha;
    }

}
