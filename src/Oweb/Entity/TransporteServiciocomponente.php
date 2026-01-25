<?php
namespace App\Oweb\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'tra_serviciocomponente')]
#[ORM\Entity]
class TransporteServiciocomponente
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var TransporteServicio
     */
    #[ORM\ManyToOne(targetEntity: TransporteServicio::class, inversedBy: 'serviciocomponentes')]
    #[ORM\JoinColumn(name: 'servicio_id', referencedColumnName: 'id', nullable: false)]
    private $servicio;

    #[ORM\Column(name: 'hora', type: 'time')]
    private $hora;

    #[ORM\Column(name: 'nombre', type: 'string', length: 100)]
    private $nombre;

    #[ORM\Column(name: 'codigo', type: 'string', length: 100)]
    private $codigo;

    #[ORM\Column(name: 'numadl', type: 'smallint')]
    private $numadl;

    #[ORM\Column(name: 'numchd', type: 'smallint')]
    private $numchd;

    #[ORM\Column(name: 'origen', type: 'string', length: 100)]
    private $origen;

    #[ORM\Column(name: 'destino', type: 'string', length: 100)]
    private $destino;

    #[ORM\Column(name: 'nota', type: 'text', nullable: true)]
    private $nota;

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
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set creado
     *
     * @param DateTime $creado
     *
     * @return TransporteServiciocomponente
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
     * @return TransporteServiciocomponente
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
     * @return TransporteServiciocomponente
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
     * Set hora
     *
     * @param DateTime $hora
     *
     * @return TransporteServiciocomponente
     */
    public function setHora($hora)
    {
        $this->hora = $hora;

        return $this;
    }

    /**
     * Get hora
     *
     * @return DateTime
     */
    public function getHora()
    {
        return $this->hora;
    }

    /**
     * Get resumen
     *
     * @return string
     */
    public function getResumen()
    {
        if($this->getNumchd() > 0){
            return sprintf('%s %s x %s+%s de %s a %s', $this->getHora()->format('H:i'), $this->getNombre(), (string)$this->getNumadl(), (string)$this->getNumchd(), $this->getOrigen(), $this->getDestino());
        }else{
            return sprintf('%s %s x %s de %s a %s', $this->getHora()->format('H:i'), $this->getNombre(), (string)$this->getNumadl(), $this->getOrigen(), $this->getDestino());
        }
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     *
     * @return TransporteServiciocomponente
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
     * Set codigo
     *
     * @param string $codigo
     *
     * @return TransporteServiciocomponente
     */
    public function setCodigo($codigo)
    {
        $this->codigo = $codigo;

        return $this;
    }

    /**
     * Get codigo
     *
     * @return string
     */
    public function getCodigo()
    {
        return $this->codigo;
    }

    /**
     * Set origen
     *
     * @param string $origen
     *
     * @return TransporteServiciocomponente
     */
    public function setOrigen($origen)
    {
        $this->origen = $origen;

        return $this;
    }

    /**
     * Get origen
     *
     * @return string
     */
    public function getOrigen()
    {
        return $this->origen;
    }

    /**
     * Set destino
     *
     * @param string $destino
     *
     * @return TransporteServiciocomponente
     */
    public function setDestino($destino)
    {
        $this->destino = $destino;

        return $this;
    }

    /**
     * Get destino
     *
     * @return string
     */
    public function getDestino()
    {
        return $this->destino;
    }

    /**
     * Set nota
     *
     * @param string $nota
     *
     * @return TransporteServiciocomponente
     */
    public function setNota($nota)
    {
        $this->nota = $nota;

        return $this;
    }

    /**
     * Get nota
     *
     * @return string
     */
    public function getNota()
    {
        return $this->nota;
    }

    /**
     * Set numadl
     *
     * @param integer $numadl
     *
     * @return TransporteServiciocomponente
     */
    public function setNumadl($numadl)
    {
        $this->numadl = $numadl;

        return $this;
    }

    /**
     * Get numadl
     *
     * @return integer
     */
    public function getNumadl()
    {
        return $this->numadl;
    }

    /**
     * Set numchd
     *
     * @param integer $numchd
     *
     * @return TransporteServiciocomponente
     */
    public function setNumchd($numchd)
    {
        $this->numchd = $numchd;

        return $this;
    }

    /**
     * Get numchd
     *
     * @return integer
     */
    public function getNumchd()
    {
        return $this->numchd;
    }
}
