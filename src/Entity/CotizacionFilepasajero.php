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
     * @ORM\ManyToOne(targetEntity="MaestroPais")
     * @ORM\JoinColumn(name="pais_id", referencedColumnName="id", nullable=false)
     */
    protected $pais;

    /**
     * @var \App\Entity\MaestroSexo
     *
     * @ORM\ManyToOne(targetEntity="MaestroSexo")
     * @ORM\JoinColumn(name="sexo_id", referencedColumnName="id", nullable=false)
     */
    protected $sexo;

    /**
     * @var \App\Entity\MaestroTipodocumento
     *
     * @ORM\ManyToOne(targetEntity="MaestroTipodocumento")
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
     * @ORM\ManyToOne(targetEntity="CotizacionFile", inversedBy="filepasajeros")
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
     * Get apellidoPaterno.
     *
     * @return string
     */
    public function getApellidoPaterno()
    {
        $apellidosArray = explode(' ', $this->apellido, 2);

        return $apellidosArray[0];
    }

    /**
     * Get apellidoMaterno.
     *
     * @return string
     */
    public function getApellidoMaterno()
    {
        $apellidosArray = explode(' ', $this->apellido, 2);

        if(!isset($apellidosArray[1])){
            return 'NR';
        }
        return $apellidosArray[1];
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

    public function getNumerodocumento(): string
    {
        return $this->numerodocumento;
    }

    public function getTipopaxperurail(): int
    {
        if($this->getEdad() >= 12) {
            return 1;
        }else{
            return 2;
        }
    }

    public function getCategoriaju(): int
    {
        if($this->getEdad() >= 18){
            return 1;
        }elseif($this->getEdad() >= 3 && $this->getEdad() <= 17){
            return 3;
        }else{
            return 0;
        }
    }

    public function getCategoriaddc(): int
    {
        if($this->getEdad() >= 18){
            return 1;
        }elseif($this->getEdad() >= 13 && $this->getEdad() <= 17){
            return 2;
        }elseif($this->getEdad() >= 3 && $this->getEdad() <= 12){
            return 7;
        }else{
           return 0;
        }
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

}
