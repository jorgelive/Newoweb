<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * FitTipodieta
 *
 * @ORM\Table(name="fit_tipodieta")
 * @ORM\Entity
 */
class FitTipodieta
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
     * @ORM\Column(name="nombre", type="string", length=255)
     */
    private $nombre;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\FitDieta", mappedBy="tipodieta")
     */
    private $dietas;

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

    public function __construct()
    {
        $this->dietas = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set nombre
     *
     * @param string $nombre
     *
     * @return FitTipodieta
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
     * Add dieta
     *
     * @param \App\Entity\FitDieta $dieta
     *
     * @return FitTipodieta
     */
    public function addDieta(\App\Entity\FitDieta $dieta)
    {
        $dieta->setTipodieta($this);

        $this->dietas[] = $dieta;

        return $this;
    }

    /**
     * Remove dieta
     *
     * @param \App\Entity\FitDieta $dieta
     */
    public function removeDieta(\App\Entity\FitDieta $dieta)
    {
        $this->dietas->removeElement($dieta);
    }

    /**
     * Get alimento
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDietas()
    {
        return $this->dietas;
    }



    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return FitTipodieta
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
     * @return FitTipodieta
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
