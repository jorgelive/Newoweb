<?php
namespace App\Oweb\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'tra_serviciooperativo')]
#[ORM\Entity]
class TransporteServiciooperativo
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var TransporteServicio
     */
    #[ORM\ManyToOne(targetEntity: TransporteServicio::class, inversedBy: 'serviciooperativos')]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id', nullable: false)]
    private $servicio;

    /**
     * @var TransporteTiposeroperativo
     */
    #[ORM\ManyToOne(targetEntity: TransporteTiposeroperativo::class)]
    #[ORM\JoinColumn(name: 'tiposeroperativo_id', referencedColumnName: 'id', nullable: false)]
    private $tiposeroperativo;

    #[ORM\Column(name: 'texto', type: 'text')]
    private $texto;

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
        return $this->getTexto() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
    }

    /**
     * Get resumen
     *
     * @return string
     */
    public function getResumen()
    {
        return sprintf("%s: %s.", $this->getTiposeroperativo()->getCodigo(), $this->getTexto());

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
     * Set texto
     *
     * @param string $texto
     *
     * @return TransporteServiciooperativo
     */
    public function setTexto($texto)
    {
        $this->texto = $texto;

        return $this;
    }

    /**
     * Get texto
     *
     * @return string
     */
    public function getTexto()
    {
        return $this->texto;
    }

    /**
     * Set creado
     *
     * @param DateTime $creado
     *
     * @return TransporteServiciooperativo
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
     * @return TransporteServiciooperativo
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
     * @return TransporteServiciooperativo
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
     * Set tiposeroperativo
     *
     * @param TransporteTiposeroperativo $tiposeroperativo
     *
     * @return TransporteServiciooperativo
     */
    public function setTiposeroperativo(TransporteTiposeroperativo $tiposeroperativo = null)
    {
        $this->tiposeroperativo = $tiposeroperativo;

        return $this;
    }

    /**
     * Get tiposeroperativo
     *
     * @return TransporteTiposeroperativo
     */
    public function getTiposeroperativo()
    {
        return $this->tiposeroperativo;
    }
}
