<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use App\Traits\MainArchivoTrait;

/**
 * CotizacionFiledocumento
 *
 * @ORM\Table(name="cot_filedocumento")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 */
class CotizacionFiledocumento
{
    use MainArchivoTrait;

    private $path = '/carga/cotizacionfiledocumento';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="date", nullable=true)
     */
    private $vencimiento;

    /**
     * @var \App\Entity\CotizacionTipofiledocumento
     *
     * @ORM\ManyToOne(targetEntity="CotizacionTipofiledocumento")
     * @ORM\JoinColumn(name="tipofiledocumento_id", referencedColumnName="id", nullable=false)
     */
    private $tipofiledocumento;

    /**
     * @var \App\Entity\CotizacionFile
     *
     * @ORM\ManyToOne(targetEntity="CotizacionFile", inversedBy="filedocumentos")
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
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $modificado;

    /**
     * @return string
     */
    public function __toString()
    {
        if (empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }elseif(!empty($this->getVencimiento())){
            return sprintf("%s | %s", $this->getVencimiento()->format('Y-m-d'), $this->getNombre());
        }else{
            return $this->getNombre();
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
     * Set vencimiento
     *
     * @param \DateTime $creado
     * @return CotizacionFiledocumento
     */
    public function setVencimiento($vencimiento)
    {
        $this->vencimiento = $vencimiento;

        return $this;
    }

    /**
     * Get vencimiento
     *
     * @return \DateTime
     */
    public function getVencimiento()
    {
        return $this->vencimiento;
    }

    /**
     * Set tipocotsocumento
     *
     * @param \App\Entity\CotizacionTipofiledocumento $tipofiledocumento
     * @return CotizacionFiledocumento
     */
    public function setTipofiledocumento(\App\Entity\CotizacionTipofiledocumento $tipofiledocumento)
    {
        $this->tipofiledocumento = $tipofiledocumento;

        return $this;
    }

    /**
     * Get tipofiledocumento
     *
     * @return \App\Entity\CotizacionTipofiledocumento
     */
    public function getTipofiledocumento()
    {
        return $this->tipofiledocumento;
    }

    /**
     * Set file
     *
     * @param \App\Entity\CotizacionFile $file
     *
     * @return CotizacionFiledocumento
     */
    public function setFile(\App\Entity\CotizacionFile $file = null)
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
     * Set creado
     *
     * @param \DateTime $creado
     * @return CotizacionFiledocumento
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
     * @return CotizacionFiledocumento
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

}
