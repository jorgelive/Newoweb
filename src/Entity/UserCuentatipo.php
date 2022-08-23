<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * UserCuentatipo
 *
 * @ORM\Table(name="use_cuentatipo")
 * @ORM\Entity
 */
class UserCuentatipo
{
    /**
     * @var integer
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
     * @Assert\NotBlank
     */
    private $nombre;

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
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="UserCuenta", mappedBy="cuentatipo", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $cuentas;

    public function __construct() {
        $this->cuentas = new ArrayCollection();
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(is_null($this->getNombre())) {
            return sprintf("Id: %s.", $this->getId());
        }

        return $this->getNombre();
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
     * @return UserCuentatipo
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
     * Set creado
     *
     * @param \DateTime $creado
     * @return UserCuentatipo
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
     * @return UserCuentatipo
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
     * Add cuenta
     *
     * @param \App\Entity\UserCuenta $cuenta
     * @return UserCuentatipo
     */
    public function addCuenta(\App\Entity\UserCuenta $cuenta)
    {
        $cuenta->setCuentatipo($this);

        $this->cuentas[] = $cuenta;

        return $this;
    }

    /**
     * Remove cuenta
     *
     * @param \App\Entity\UserCuenta $cuenta
     */
    public function removeUsercuenta(\App\Entity\UserCuenta $cuenta)
    {
        $this->cuentas->removeElement($cuenta);
    }

    /**
     * Get cuentas
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getCuentas()
    {
        return $this->cuentas;
    }

    public function removeCuenta(UserCuenta $cuenta): self
    {
        if($this->cuentas->removeElement($cuenta)) {
            // set the owning side to null (unless already changed)
            if($cuenta->getCuentatipo() === $this) {
                $cuenta->setCuentatipo(null);
            }
        }

        return $this;
    }
}
