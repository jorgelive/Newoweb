<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * FitMedidaalimento
 */
#[ORM\Table(name: 'fit_medidaalimento')]
#[ORM\Entity]
class FitMedidaalimento
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
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private $nombre;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: 'FitAlimento', mappedBy: 'medidaalimento')]
    private $alimentos;

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

    public function __construct()
    {
        $this->alimentos = new ArrayCollection();
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
     * @return FitMedidaalimento
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
     * Add alimento
     *
     * @param \App\Entity\FitAlimento $alimento
     *
     * @return FitMedidaalimento
     */
    public function addAlimento(\App\Entity\FitAlimento $alimento)
    {
        $alimento->setTipoalimento($this);

        $this->alimentos[] = $alimento;

        return $this;
    }

    /**
     * Remove alimento
     *
     * @param \App\Entity\FitAlimento $alimento
     */
    public function removeDietaalimento(\App\Entity\FitAlimento $alimento)
    {
        $this->alimentos->removeElement($alimento);
    }

    /**
     * Get alimento
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDietaalimentos()
    {
        return $this->alimentos;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return FitMedidaalimento
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
     * @return FitMedidaalimento
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
     * @return Collection<int, FitAlimento>
     */
    public function getAlimentos(): Collection
    {
        return $this->alimentos;
    }

    public function removeAlimento(FitAlimento $alimento): self
    {
        if($this->alimentos->removeElement($alimento)) {
            // set the owning side to null (unless already changed)
            if($alimento->getMedidaalimento() === $this) {
                $alimento->setMedidaalimento(null);
            }
        }

        return $this;
    }


}
