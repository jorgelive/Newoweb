<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * FitDietaalimento
 */
#[ORM\Table(name: 'fit_dietaalimento')]
#[ORM\Entity]
class FitDietaalimento
{
    /**
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'cantidad', type: 'decimal', precision: 7, scale: 2)]
    private $cantidad = '1';

    /**
     * @var \App\Entity\FitDietacomida
     */
    #[ORM\ManyToOne(targetEntity: 'FitDietacomida', inversedBy: 'dietaalimentos')]
    #[ORM\JoinColumn(name: 'dietacomida_id', referencedColumnName: 'id', nullable: false)]
    protected $dietacomida;

    /**
     * @var \App\Entity\FitAlimento
     */
    #[ORM\ManyToOne(targetEntity: 'FitAlimento')]
    #[ORM\JoinColumn(name: 'alimento_id', referencedColumnName: 'id', nullable: false)]
    protected $alimento;

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
     * @return string
     */
    public function __toString()
    {

        if(empty($this->getAlimento())){
            return sprintf("Id: %s.", $this->getId()) ?? '';
        }
        return $this->getAlimento()->getNombre();
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
     * Set cantidad
     *
     * @param string $cantidad
     *
     * @return FitDietaalimento
     */
    public function setCantidad($cantidad)
    {
        $this->cantidad = $cantidad;
    
        return $this;
    }

    /**
     * Get cantidad
     *
     * @return string
     */
    public function getCantidad()
    {
        return $this->cantidad;
    }

    public function getMedidaAlimento()
    {
        if(!empty($this->getAlimento())){
            return $this->getAlimento()->getMedidaalimento()->getNombre();
        }

        return "";
    }

    public function getCantidadAlimento()
    {
        if(!empty($this->getAlimento())){
            return $this->getAlimento()->getCantidad() * $this->getCantidad();
        }

        return "0";
    }

    public function getMedidaCantidadAlimento()
    {

        return sprintf('%s %s', $this->getCantidadAlimento(), $this->getMedidaAlimento());
    }


    public function getGrasa()
    {
        if(!empty($this->getAlimento())){
            return $this->getAlimento()->getGrasa();
        }

        return 0;

    }

    public function getGrasaTotal()
    {
        return $this->getGrasa() * $this->getCantidad();
    }

    public function getGrasaCalorias()
    {
        return $this->getGrasaTotal() * 9;
    }

    public function getCarbohidrato()
    {
        if(!empty($this->getAlimento())){
            return $this->getAlimento()->getCarbohidrato();
        }

        return 0;
    }

    public function getCarbohidratoTotal()
    {
        return $this->getCarbohidrato() * $this->getCantidad();
    }

    public function getCarbohidratoCalorias()
    {
        return $this->getCarbohidratoTotal() * 4;

    }

    public function getProteina()
    {
        if(!empty($this->getAlimento())){
            return $this->getAlimento()->getProteina();
        }
        return 0;

    }

    public function getProteinaTotal()
    {
        return $this->getProteina() * $this->getCantidad();
    }

    public function getProteinaTotalAlto()
    {
        if(empty($this->getAlimento()) || $this->getAlimento()->isProteinaaltovalor() === false){
            return 0;
        }
        return $this->getProteina() * $this->getCantidad();
    }

    public function getProteinaCalorias()
    {
        return $this->getProteinaTotal() * 4;
    }

    public function getTotalCalorias()
    {
        return $this->getGrasaCalorias() + $this->getCarbohidratoCalorias() + $this->getProteinaCalorias();
    }

    public function getEnergiaCalorias()
    {
        return $this->getGrasaCalorias() + $this->getCarbohidratoCalorias();
    }





    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return FitDietaalimento
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
     * @return FitDietaalimento
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
     * Set dietacomida
     *
     * @param \App\Entity\FitDietacomida $dietacomida
     *
     * @return FitDietaalimento
     */
    public function setDietacomida(\App\Entity\FitDietacomida $dietacomida = null)
    {
        $this->dietacomida = $dietacomida;
    
        return $this;
    }

    /**
     * Get dietacomida
     *
     * @return \App\Entity\FitDietacomida
     */
    public function getDietacomida()
    {
        return $this->dietacomida;
    }

    /**
     * Set alimento
     *
     * @param \App\Entity\FitAlimento $alimento
     *
     * @return FitDietaalimento
     */
    public function setAlimento(\App\Entity\FitAlimento $alimento = null)
    {
        $this->alimento = $alimento;
    
        return $this;
    }

    /**
     * Get alimento
     *
     * @return \App\Entity\FitAlimento
     */
    public function getAlimento()
    {
        return $this->alimento;
    }


}
