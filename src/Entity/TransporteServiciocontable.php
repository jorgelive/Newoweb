<?php
namespace App\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'tra_serviciocontable')]
#[ORM\Entity]
class TransporteServiciocontable
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var TransporteServicio
     */
    #[ORM\ManyToOne(targetEntity: 'TransporteServicio', inversedBy: 'serviciocontables')]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id', nullable: false)]
    private $servicio;

    /**
     * @var ComprobanteComprobante
     */
    #[ORM\ManyToOne(targetEntity: 'ComprobanteComprobante', inversedBy: 'serviciocontables')]
    #[ORM\JoinColumn(name: 'comprobante_id', referencedColumnName: 'id', nullable: false)]
    private $comprobante;

    #[ORM\Column(type: 'string', length: 250)]
    private $descripcion;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private $total;

    /**
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    /**
     * @return string
     */
    public function __toString()
    {

        if(!empty($this->getServicio())){
            $comp = [];
            foreach($this->getServicio()->getServiciocomponentes() as $componente):
                $comp[] = sprintf('%s', $componente->getNombre());
            endforeach;
            return sprintf('%s %s [%s] (%s)', $this->getServicio()->getFechahorainicio()->format('Y-m-d'), $this->getServicio(), implode(', ', $comp), $this->getTotal());
        }else{
            return '';
        }
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
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
     * Set descripcion
     *
     * @param string $descripcion
     *
     * @return TransporteServiciocontable
     */
    public function setDescripcion($descripcion)
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    /**
     * Get descripcion
     *
     * @return string
     */
    public function getDescripcion()
    {
        return $this->descripcion;
    }

    /**
     * Set total
     *
     * @param string $total
     *
     * @return TransporteServiciocontable
     */
    public function setTotal($total)
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Get total
     *
     * @return string
     */
    public function getTotal()
    {
        return $this->total;
    }

    /**
     * Set creado
     *
     * @param DateTime $creado
     *
     * @return TransporteServiciocontable
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;

        return $this;
    }

    /**
     * Get creado
     *
     * @return DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     *
     * @param DateTime $modificado
     *
     * @return TransporteServiciocontable
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;

        return $this;
    }

    /**
     * Get modificado
     *
     * @return DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set servicio
     *
     * @param TransporteServicio $servicio
     *
     * @return TransporteServiciocontable
     */
    public function setServicio(TransporteServicio $servicio = null)
    {
        $this->servicio = $servicio;

        return $this;
    }

    /**
     * Get servicio
     *
     * @return TransporteServicio
     */
    public function getServicio()
    {
        return $this->servicio;
    }

    /**
     * Set comprobante
     *
     * @param ComprobanteComprobante $comprobante
     *
     * @return TransporteServiciocontable
     */
    public function setComprobante(ComprobanteComprobante $comprobante)
    {
        $this->comprobante = $comprobante;

        return $this;
    }

    /**
     * Get comprobante
     *
     * @return ComprobanteComprobante
     */
    public function getComprobante()
    {
        return $this->comprobante;
    }

}
