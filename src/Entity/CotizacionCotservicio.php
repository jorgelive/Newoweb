<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionCotservicio
 */
#[ORM\Table(name: 'cot_cotservicio')]
#[ORM\Entity(repositoryClass: 'App\Repository\CotizacionCotservicioRepository')]
class CotizacionCotservicio
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'fechahorainicio', type: 'datetime')]
    private $fechahorainicio;

    /**
     * @var \DateTime
     */
    #[ORM\Column(name: 'fechahorafin', type: 'datetime', nullable: true)]
    private $fechahorafin;

    /**
     * @var \App\Entity\CotizacionCotizacion
     */
    #[ORM\ManyToOne(targetEntity: 'CotizacionCotizacion', inversedBy: 'cotservicios')]
    #[ORM\JoinColumn(name: 'cotizacion_id', referencedColumnName: 'id', nullable: false)]
    protected $cotizacion;

    /**
     * @var \App\Entity\ServicioServicio
     */
    #[ORM\ManyToOne(targetEntity: 'ServicioServicio')]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id', nullable: false)]
    protected $servicio;

    /**
     * @var \App\Entity\SErvicioItinerario
     */
    #[ORM\ManyToOne(targetEntity: 'ServicioItinerario')]
    #[ORM\JoinColumn(name: 'itinerario_id', referencedColumnName: 'id', nullable: false)]
    protected $itinerario;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: 'CotizacionCotcomponente', mappedBy: 'cotservicio', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechahorainicio' => 'ASC'])]
    private $cotcomponentes;

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
     * Constructor
     */
    public function __construct()
    {
        $this->cotcomponentes = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newCotcomponentes = new ArrayCollection();
            foreach($this->cotcomponentes as $cotcomponente) {
                $newCotcomponente = clone $cotcomponente;
                $newCotcomponente->setCotservicio($this);
                $newCotcomponentes->add($newCotcomponente);
            }
            $this->cotcomponentes = $newCotcomponentes;
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getServicio())){
            return sprintf("Id: %s.", $this->getId()) ?? '';
        }
        return $this->getServicio()->getNombre();
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
     * Get resumen
     *
     * @return string
     */
    public function getResumen()
    {
        //return sprintf('%s: %s', $this->getServicio()->getNombre(), $this->getCotizacion()->getFile()->getNombre());
        return sprintf('%s x%s: %s', $this->getCotizacion()->getFile()->getNombre(), $this->getCotizacion()->getNumeropasajeros(), $this->getServicio()->getNombre());
    }

    /**
     * Set fechahorainicio
     *
     * @param \DateTime $fechahorainicio
     *
     * @return CotizacionCotservicio
     */
    public function setFechahorainicio($fechahorainicio)
    {
        $this->fechahorainicio = $fechahorainicio;
    
        return $this;
    }

    /**
     * Get fechahorainicio
     *
     * @return \DateTime
     */
    public function getFechahorainicio()
    {
        return $this->fechahorainicio;
    }

    public function getFechainicio(): ?\DateTime
    {
        return (new \DateTime($this->fechahorainicio->format('Y-m-d')));
    }

    /**
     * Set fechahorafin
     *
     * @param \DateTime $fechahorafin
     *
     * @return CotizacionCotservicio
     */
    public function setFechahorafin($fechahorafin)
    {
        $this->fechahorafin = $fechahorafin;
    
        return $this;
    }

    /**
     * Get fechahorafin
     *
     * @return \DateTime
     */
    public function getFechahorafin()
    {
        return $this->fechahorafin;
    }

    public function getFechafin(): ?\DateTime
    {
        return (new \DateTime($this->fechahorafin->format('Y-m-d')));
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return CotizacionCotservicio
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
     * @return CotizacionCotservicio
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
     * Set cotizacion
     *
     * @param \App\Entity\CotizacionCotizacion $cotizacion
     *
     * @return CotizacionCotservicio
     */
    public function setCotizacion(\App\Entity\CotizacionCotizacion $cotizacion = null)
    {
        $this->cotizacion = $cotizacion;
    
        return $this;
    }

    /**
     * Get cotizacion
     *
     * @return \App\Entity\CotizacionCotizacion
     */
    public function getCotizacion()
    {
        return $this->cotizacion;
    }

    /**
     * Set servicio
     *
     * @param \App\Entity\ServicioServicio $servicio
     *
     * @return CotizacionCotservicio
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
     * Set itinerario
     *
     * @param \App\Entity\ServicioItinerario $itinerario
     *
     * @return CotizacionCotservicio
     */
    public function setItinerario(\App\Entity\ServicioItinerario $itinerario = null)
    {
        $this->itinerario = $itinerario;
    
        return $this;
    }

    /**
     * Get itinerario
     *
     * @return \App\Entity\ServicioItinerario
     */
    public function getItinerario()
    {
        return $this->itinerario;
    }

    /**
     * Add cotcomponente
     *
     * @param \App\Entity\CotizacionCotcomponente $cotcomponente
     *
     * @return CotizacionCotservicio
     */
    public function addCotcomponente(\App\Entity\CotizacionCotcomponente $cotcomponente)
    {
        $cotcomponente->setCotservicio($this);

        $this->cotcomponentes[] = $cotcomponente;
    
        return $this;
    }

    /**
     * Remove cotcomponente
     *
     * @param \App\Entity\CotizacionCotcomponente $cotcomponente
     */
    public function removeCotcomponente(\App\Entity\CotizacionCotcomponente $cotcomponente)
    {
        $this->cotcomponentes->removeElement($cotcomponente);
    }

    /**
     * Get cotcomponentes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCotcomponentes()
    {
        return $this->cotcomponentes;
    }

}
