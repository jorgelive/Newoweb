<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionFilepasajero
 *
 * @ORM\Table(name="cot_filepasajero")
 * @ORM\Entity
 */
class CotizacionFilepasajero
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
     * @ORM\Column(name="nombre", type="string", length=100)
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(name="apellido", type="string", length=100)
     */
    private $apellido;

    /**
     * @var \App\Entity\MaestroPais
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroPais")
     * @ORM\JoinColumn(name="pais_id", referencedColumnName="id", nullable=false)
     */
    protected $pais;

    /**
     * @var \App\Entity\MaestroSexo
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroSexo")
     * @ORM\JoinColumn(name="sexo_id", referencedColumnName="id", nullable=false)
     */
    protected $sexo;

    /**
     * @var \App\Entity\MaestroTipodocumento
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroTipodocumento")
     * @ORM\JoinColumn(name="tipodocumento_id", referencedColumnName="id", nullable=false)
     */
    protected $tipodocumento;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fechanacimiento", type="date")
     */
    protected $fechanacimiento;

    /**
     * @var int
     *
     * @ORM\Column(name="numerodocumento", type="string", length=100)
     */
    private $numerodocumento;

    /**
     * @var \App\Entity\CotizacionFile
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\CotizacionFile", inversedBy="filepasajeros")
     * @ORM\JoinColumn(name="file_id", referencedColumnName="id", nullable=false)
     */
    protected $file;

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
     * @return string
     */
    public function __toString()
    {
        return sprintf('%s %s', $this->getNombre(), $this->getApellido());
    }




    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set nombre.
     *
     * @param string $nombre
     *
     * @return CotizacionFilepasajero
     */
    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    /**
     * Get nombre.
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }

    /**
     * Set apellido.
     *
     * @param string $apellido
     *
     * @return CotizacionFilepasajero
     */
    public function setApellido($apellido)
    {
        $this->apellido = $apellido;
    
        return $this;
    }

    /**
     * Get apellido.
     *
     * @return string
     */
    public function getApellido()
    {
        return $this->apellido;
    }

    /**
     * Set fechanacimiento.
     *
     * @param \DateTime $fechanacimiento
     *
     * @return CotizacionFilepasajero
     */
    public function setFechanacimiento($fechanacimiento)
    {
        $this->fechanacimiento = $fechanacimiento;
    
        return $this;
    }

    /**
     * Get fechanacimiento.
     *
     * @return \DateTime
     */
    public function getFechanacimiento()
    {
        return $this->fechanacimiento;
    }


    /**
     * Get edad.
     *
     * @return int
     */
    public function getEdad()
    {
        $hoy = new \DateTime();
        $diferencia = $hoy->diff($this->fechanacimiento);

        return $diferencia->y;
    }

    /**
     * Set numerodocumento.
     *
     * @param string $numerodocumento
     *
     * @return CotizacionFilepasajero
     */
    public function setNumerodocumento($numerodocumento)
    {
        $this->numerodocumento = $numerodocumento;
    
        return $this;
    }

    /**
     * Get numerodocumento.
     *
     * @return string
     */
    public function getNumerodocumento()
    {
        return $this->numerodocumento;
    }

    /**
     * Set creado.
     *
     * @param \DateTime $creado
     *
     * @return CotizacionFilepasajero
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;
    
        return $this;
    }

    /**
     * Get creado.
     *
     * @return \DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado.
     *
     * @param \DateTime $modificado
     *
     * @return CotizacionFilepasajero
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    /**
     * Get modificado.
     *
     * @return \DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set pais.
     *
     * @param \App\Entity\MaestroPais $pais
     *
     * @return CotizacionFilepasajero
     */
    public function setPais(\App\Entity\MaestroPais $pais)
    {
        $this->pais = $pais;
    
        return $this;
    }

    /**
     * Get pais.
     *
     * @return \App\Entity\MaestroPais
     */
    public function getPais()
    {
        return $this->pais;
    }

    /**
     * Set sexo.
     *
     * @param \App\Entity\MaestroSexo $sexo
     *
     * @return CotizacionFilepasajero
     */
    public function setSexo(\App\Entity\MaestroSexo $sexo)
    {
        $this->sexo = $sexo;
    
        return $this;
    }

    /**
     * Get sexo.
     *
     * @return \App\Entity\MaestroSexo
     */
    public function getSexo()
    {
        return $this->sexo;
    }

    /**
     * Set tipodocumento.
     *
     * @param \App\Entity\MaestroTipodocumento $tipodocumento
     *
     * @return CotizacionFilepasajero
     */
    public function setTipodocumento(\App\Entity\MaestroTipodocumento $tipodocumento)
    {
        $this->tipodocumento = $tipodocumento;
    
        return $this;
    }

    /**
     * Get tipodocumento.
     *
     * @return \App\Entity\MaestroTipodocumento
     */
    public function getTipodocumento()
    {
        return $this->tipodocumento;
    }

    /**
     * Set file.
     *
     * @param \App\Entity\CotizacionFile $file
     *
     * @return CotizacionFilepasajero
     */
    public function setFile(\App\Entity\CotizacionFile $file)
    {
        $this->file = $file;
    
        return $this;
    }

    /**
     * Get file.
     *
     * @return \App\Entity\CotizacionFile
     */
    public function getFile()
    {
        return $this->file;
    }

    /**
     * Set nacimiento.
     *
     * @param string $nacimiento
     *
     * @return CotizacionFilepasajero
     */
    public function setNacimiento($nacimiento)
    {
        $this->nacimiento = $nacimiento;
    
        return $this;
    }

    /**
     * Get nacimiento.
     *
     * @return string
     */
    public function getNacimiento()
    {
        return $this->nacimiento;
    }
}
