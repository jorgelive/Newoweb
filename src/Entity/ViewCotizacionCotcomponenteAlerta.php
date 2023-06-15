<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ViewCotizacionCotcomponenteAlerta
 * @ORM\Entity(readOnly=true)
 * @ORM\Table(name="view_cot_cotcomponente_alerta")
 */
class ViewCotizacionCotcomponenteAlerta
{
    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * @var \App\Entity\CotizacionCotservicio
     *
     * @ORM\ManyToOne(targetEntity="CotizacionCotservicio")
     * @ORM\JoinColumn(name="cotservicio_id", referencedColumnName="id", nullable=false)
     */
    protected $cotservicio;

    /**
     * @var \App\Entity\ServicioComponente
     *
     * @ORM\ManyToOne(targetEntity="ServicioComponente")
     * @ORM\JoinColumn(name="componente_id", referencedColumnName="id", nullable=false)
     */
    protected $componente;

    /**
     * @var \App\Entity\CotizacionEstadocotcomponente
     *
     * @ORM\ManyToOne(targetEntity="CotizacionEstadocotcomponente")
     * @ORM\JoinColumn(name="estadocotcomponente_id", referencedColumnName="id", nullable=false)
     */
    protected $estadocotcomponente;

    /**
     * @var int
     *
     * @ORM\Column(name="cantidad", type="integer", options={"default": 1})
     */
    private $cantidad;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fechahorainicio", type="datetime")
     */
    private $fechahorainicio;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fechahorafin", type="datetime")
     */
    private $fechahorafin;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fechaalerta", type="datetime")
     */
    private $fechaalerta;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $anticipacionalerta;


    /**
     * Constructor
     */
    public function __construct()
    {

    }

    public function __toString(): string
    {
        if(empty($this->getComponente())){
            return sprintf('id: %s', $this->getId());
        }
        if($this->getCantidad() > 1){
            $infocomponente = sprintf('%s x%s', $this->getComponente()->getNombre(), $this->getCantidad());
        }else{
            $infocomponente = $this->getComponente()->getNombre();
        }
        return sprintf('%s x%s: %s', $this->getCotservicio()->getCotizacion()->getFile()->getNombre(), $this->getCotservicio()->getCotizacion()->getNumeropasajeros(), $infocomponente);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function getEstadocotcomponente(): ?CotizacionEstadocotcomponente
    {
        return $this->estadocotcomponente;
    }

    public function getCotservicio(): ?CotizacionCotservicio
    {
        return $this->cotservicio;
    }

    public function getComponente(): ?ServicioComponente
    {
        return $this->componente;
    }

    public function getCantidad(): ?int
    {
        return $this->cantidad;
    }

    public function getFechahorainicio(): ?\DateTime
    {
        return $this->fechahorainicio;
    }

    public function getFechahorafin(): ?\DateTime
    {
        return $this->fechahorafin;
    }

    public function getFechaalerta(): ?\DateTime
    {
        return $this->fechahorafin;
    }

    public function getAnticipacionalerta(): ?int
    {
        return $this->anticipacionalerta;
    }
}
