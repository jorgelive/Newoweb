<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ServicioItinerario
 */
#[ORM\Table(name: 'ser_itinerario')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: 'App\Entity\ServicioItinerarioTranslation')]
class ServicioItinerario
{

    /**
     * @var int
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var ArrayCollection
     */
    #[ORM\OneToMany(targetEntity: 'ServicioItinerarioTranslation', mappedBy: 'object', cascade: ['persist', 'remove'])]
    protected $translations;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 100)]
    private $nombre;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private $titulo;

    /**
     * @var \DateTime
     */
    #[ORM\Column(type: 'time')]
    private $hora;

    /**
     * @var string
     */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 1)]
    private $duracion;

    /**
     * @var \DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var \DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    /**
     * @var \App\Entity\ServicioServicio
     */
    #[ORM\ManyToOne(targetEntity: 'ServicioServicio', inversedBy: 'itinerarios')]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id', nullable: false)]
    protected $servicio;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: 'ServicioItinerariodia', mappedBy: 'itinerario', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private $itinerariodias;

    #[Gedmo\Locale]
    private $locale;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->itinerariodias = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newItinerariodias = new ArrayCollection();
            foreach($this->itinerariodias as $itinerariodia) {
                $newItinerariodia = clone $itinerariodia;
                $newItinerariodia->setItinerario($this);
                $newItinerariodias->add($newItinerariodia);
            }
            $this->itinerariodias = $newItinerariodias;

        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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

    public function addTranslation(ServicioItinerariodiaTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
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
     * @return ServicioItinerario
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
     * Set titulo
     *
     * @param string $titulo
     *
     * @return ServicioItinerario
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

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return ServicioItinerario
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
     * @return ServicioItinerario
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
     * Set servicio
     *
     * @param \App\Entity\ServicioServicio $servicio
     *
     * @return ServicioItinerario
     */
    public function setServicio(\App\Entity\ServicioServicio $servicio = null)
    {
        $this->servicio = $servicio;
    
        return $this;
    }

    /**
     * Get servicio
     *
     * @return \App\Entity\ServicioServicio
     */
    public function getServicio()
    {
        return $this->servicio;
    }

    /**
     * Add itinerariodia
     *
     * @param \App\Entity\ServicioItinerariodia $itinerariodia
     *
     * @return ServicioItinerario
     */
    public function addItinerariodia(\App\Entity\ServicioItinerariodia $itinerariodia)
    {
        $itinerariodia->setItinerario($this);

        $this->itinerariodias[] = $itinerariodia;
    
        return $this;
    }

    /**
     * Remove itinerariodia
     *
     * @param \App\Entity\ServicioItinerariodia $itinerariodia
     */
    public function removeItinerariodia(\App\Entity\ServicioItinerariodia $itinerariodia)
    {
        $this->itinerariodias->removeElement($itinerariodia);
    }

    /**
     * Get itinerariodias
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getItinerariodias()
    {
        return $this->itinerariodias;
    }

    /**
     * Set hora
     *
     * @param \DateTime $hora
     *
     * @return ServicioItinerario
     */
    public function setHora($hora)
    {
        $this->hora = $hora;
    
        return $this;
    }

    /**
     * Get hora
     *
     * @return \DateTime
     */
    public function getHora()
    {
        return $this->hora;
    }

    /**
     * Set duracion
     *
     * @param string $duracion
     *
     * @return ServicioItinerario
     */
    public function setDuracion($duracion)
    {
        $this->duracion = $duracion;
    
        return $this;
    }

    /**
     * Get duracion
     *
     * @return string
     */
    public function getDuracion()
    {
        return $this->duracion;
    }

}
