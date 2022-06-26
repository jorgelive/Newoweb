<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CuentaTipo
 *
 * @ORM\Table(name="cue_tipo")
 * @ORM\Entity
 */
class CuentaTipo
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
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CuentaClase", mappedBy="tipo", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $clases;

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

    public function __construct() {
        $this->clases = new ArrayCollection();
    }

    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * @return CuentaTipo
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
     * Set creado.
     *
     * @param \DateTime $creado
     *
     * @return CuentaTipo
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
     * @return CuentaTipo
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
     * Add clase.
     *
     * @param \App\Entity\CuentaClase $clase
     *
     * @return CuentaTipo
     */
    public function addClase(\App\Entity\CuentaClase $clase)
    {
        $clase->setTipo($this);

        $this->clases[] = $clase;
    
        return $this;
    }

    /**
     * Remove clase.
     *
     * @param \App\Entity\CuentaClase $clase
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeClase(\App\Entity\CuentaClase $clase)
    {
        return $this->clases->removeElement($clase);
    }

    /**
     * Get clases.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getClases()
    {
        return $this->clases;
    }

    public function addClas(CuentaClase $clas): self
    {
        if (!$this->clases->contains($clas)) {
            $this->clases[] = $clas;
            $clas->setTipo($this);
        }

        return $this;
    }

    public function removeClas(CuentaClase $clas): self
    {
        if ($this->clases->removeElement($clas)) {
            // set the owning side to null (unless already changed)
            if ($clas->getTipo() === $this) {
                $clas->setTipo(null);
            }
        }

        return $this;
    }
}
