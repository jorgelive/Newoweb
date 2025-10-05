<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * FitAlimento
 */
#[ORM\Table(name: 'fit_alimento')]
#[ORM\Entity]
class FitAlimento
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
    #[ORM\Column(name: 'nombre', type: 'string', length: 100)]
    private $nombre;

    /**
     * @var string
     */
    #[ORM\Column(name: 'grasa', type: 'decimal', precision: 7, scale: 2)]
    private $grasa;

    /**
     * @var string
     */
    #[ORM\Column(name: 'carbohidrato', type: 'decimal', precision: 7, scale: 2)]
    private $carbohidrato;

    /**
     * @var string
     */
    #[ORM\Column(name: 'proteina', type: 'decimal', precision: 7, scale: 2)]
    private $proteina;

    /**
     * @var string
     */
    #[ORM\Column(name: 'cantidad', type: 'decimal', precision: 7, scale: 2)]
    private $cantidad;

    /**
     * @var bool
     */
    #[ORM\Column(name: 'proteinaaltovalor', type: 'boolean', options: ['default' => 0])]
    private $proteinaaltovalor;

    /**
     * @var \App\Entity\FitTipoalimento
     */
    #[ORM\ManyToOne(targetEntity: 'FitTipoalimento', inversedBy: 'alimentos')]
    #[ORM\JoinColumn(name: 'tipoalimento_id', referencedColumnName: 'id', nullable: false)]
    protected $tipoalimento;

    /**
     * @var \App\Entity\FitMedidaalimento
     */
    #[ORM\ManyToOne(targetEntity: 'FitMedidaalimento', inversedBy: 'alimentos')]
    #[ORM\JoinColumn(name: 'mediaalimento_id', referencedColumnName: 'id', nullable: false)]
    protected $medidaalimento;

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

        if(empty($this->getMedidaalimento())) {
            return sprintf("Id: %s.", $this->getId());
        }

        return sprintf('%s (%s %s)', $this->getNombre(), $this->getCantidad(), $this->getMedidaalimento()->getNombre());
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
     * Set proteinaaltovalor
     *
     * @param boolean $proteinaaltovalor
     *
     * @return FitAlimento
     */
    public function setProteinaaltovalor($proteinaaltovalor)
    {
        $this->proteinaaltovalor = $proteinaaltovalor;
    
        return $this;
    }

    /**
     * Is proteinaaltovalor
     *
     * @return boolean
     */
    public function isProteinaaltovalor(): ?bool
    {
        return $this->proteinaaltovalor;
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     *
     * @return FitAlimento
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
     * Set grasa
     *
     * @param string $grasa
     *
     * @return FitAlimento
     */
    public function setGrasa($grasa)
    {
        $this->grasa = $grasa;
    
        return $this;
    }

    /**
     * Get grasa
     *
     * @return string
     */
    public function getGrasa()
    {
        return $this->grasa;
    }

    /**
 * Set carbohidrato
 *
 * @param string $carbohidrato
 *
 * @return FitAlimento
 */
    public function setCarbohidrato($carbohidrato)
    {
        $this->carbohidrato = $carbohidrato;

        return $this;
    }

    /**
     * Get carbohidrato
     *
     * @return string
     */
    public function getCarbohidrato()
    {
        return $this->carbohidrato;
    }

    /**
     * Set proteina
     *
     * @param string $proteina
     *
     * @return FitAlimento
     */
    public function setProteina($proteina)
    {
        $this->proteina = $proteina;

        return $this;
    }

    /**
     * Get proteina
     *
     * @return string
     */
    public function getProteina()
    {
        return $this->proteina;
    }

    /**
     * Set cantidad
     *
     * @param string $cantidad
     *
     * @return FitAlimento
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

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return FitAlimento
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
     * @return FitAlimento
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
     * Set tipoalimento
     *
     * @param \App\Entity\FitTipoalimento $tipoalimento
     *
     * @return FitAlimento
     */
    public function setTipoalimento(\App\Entity\FitTipoalimento $tipoalimento = null)
    {
        $this->tipoalimento = $tipoalimento;
    
        return $this;
    }

    /**
     * Get tipoalimento
     *
     * @return \App\Entity\FitTipoalimento
     */
    public function getTipoalimento()
    {
        return $this->tipoalimento;
    }

    /**
     * Set medidaalimento
     *
     * @param \App\Entity\FitMedidaalimento $medidaalimento
     *
     * @return FitAlimento
     */
    public function setMedidaalimento(\App\Entity\FitMedidaalimento $medidaalimento = null)
    {
        $this->medidaalimento = $medidaalimento;

        return $this;
    }

    /**
     * Get medidaalimento
     *
     * @return \App\Entity\FitMedidaalimento
     */
    public function getMedidaalimento()
    {
        return $this->medidaalimento;
    }




}
