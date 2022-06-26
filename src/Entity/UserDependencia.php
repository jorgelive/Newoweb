<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * UserDependencia
 *
 * @ORM\Table(name="use_dependencia")
 * @ORM\Entity
 */

class UserDependencia
{

    /**
     * @var integer
     *
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     * @Assert\NotBlank
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $email;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=200, nullable=true)
     */
    private $direccion;

    /**
     * @ORM\Column(type="string", length=10)
     */
    private $color;

    /**
     * @var \App\Entity\UserOrganizacion
     *
     * @ORM\ManyToOne(targetEntity="UserOrganizacion", inversedBy="dependencias")
     * @ORM\JoinColumn(name="organizacion_id", referencedColumnName="id", nullable=false)
     */
    protected $organizacion;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="UserUser", mappedBy="dependencia", cascade={"persist","remove"}, orphanRemoval=true)
     */
    protected $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }


    /**
     * @return string
     */
    public function getOrganizaciondependencia()
    {
        if(is_null($this->getNombre())) {
            $nombre = sprintf("Id: %s.", $this->getId());
        }else{
            $nombre = $this->getNombre();
        }

        if(!is_null($this->getOrganizacion())){
            $organizacion = $this->getOrganizacion()->getNombre();
            if(empty($organizacion)){
                $organizacion = sprintf("Id: %s.", $this->getOrganizacion()->getId());
            }
        }else{
            $organizacion = 'No asignado no asignado';
        }

        return sprintf("%s - %s", $organizacion, $nombre);
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
     * @return UserDependencia
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
     * Set email
     *
     * @param string $email
     * @return UserDependencia
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get email
     *
     * @return string 
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Set direccion
     *
     * @param string $direccion
     * @return UserDependencia
     */
    public function setDireccion($direccion)
    {
        $this->direccion = $direccion;

        return $this;
    }

    /**
     * Get direccion
     *
     * @return string 
     */
    public function getDireccion()
    {
        return $this->direccion;
    }

    /**
     * Set organizacion
     *
     * @param \App\Entity\UserOrganizacion $organizacion
     * @return UserDependencia
     */
    public function setOrganizacion(\App\Entity\UserOrganizacion $organizacion = null)
    {
        $this->organizacion = $organizacion;

        return $this;
    }

    /**
     * Get organizacion
     *
     * @return \App\Entity\UserOrganizacion
     */
    public function getOrganizacion()
    {
        return $this->organizacion;
    }

    /**
     * Add users
     *
     * @param \App\Entity\UserUser $user
     * @return UserDependencia
     */
    public function addUser(\App\Entity\UserUser $user)
    {
        $user->setDependencia($this);

        $this->users[] = $user;

        return $this;
    }

    /**
     * Remove users
     *
     * @param \App\Entity\UserUser $user
     */
    public function removeUser(\App\Entity\UserUser $user)
    {
        $this->users->removeElement($user);
    }

    /**
     * Get users
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getOrganizaciondependencia();
    }

    /**
     * Set color
     *
     * @param string $color
     *
     * @return UserDependencia
     */
    public function setColor($color)
    {
        $this->color = $color;
    
        return $this;
    }

    /**
     * Get color
     *
     * @return string
     */
    public function getColor()
    {
        return $this->color;
    }
}
