<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * UserOrganizacion
 */
#[ORM\Table(name: 'use_organizacion')]
#[ORM\Entity]
class UserOrganizacion
{

    /**
     * @var integer
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private $nombre;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private $razonsocial;

    /**
     * @var string
     */
    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 11, unique: true)]
    private $numerodocumento;

    /**
     * @var string
     */
    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 100)]
    private $email;

    /**
     * @var string
     */
    #[Assert\NotBlank]
    #[ORM\Column(type: 'string', length: 200)]
    private $direccion;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    #[ORM\OneToMany(targetEntity: 'UserDependencia', mappedBy: 'organizacion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected $dependencias;

    public function __construct()
    {
        $this->dependencias = new ArrayCollection();
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
     * @return UserOrganizacion
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
     * Set numerodocumento
     *
     * @param string $numerodocumento
     * @return UserOrganizacion
     */
    public function setNumerodocumento($numerodocumento)
    {
        $this->numerodocumento = $numerodocumento;

        return $this;
    }

    /**
     * Get numerodocumento
     *
     * @return string 
     */
    public function getNumerodocumento()
    {
        return $this->numerodocumento;
    }

    /**
     * Set email
     *
     * @param string $email
     * @return UserOrganizacion
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
     * @return UserOrganizacion
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
     * Add dependencias
     *
     * @param \App\Entity\UserDependencia $dependencia
     * @return UserOrganizacion
     */
    public function addDependencia(\App\Entity\UserDependencia $dependencia)
    {
        $dependencia->setOrganizacion($this);

        $this->dependencias[] = $dependencia;

        return $this;
    }

    public function setDependencias($dependencias)
    {
        if(count($dependencias) > 0) {
            foreach($dependencias as $dependencia) {
                $this->addDependencia($dependencia);
            }
        }
        return $this;
    }

    /**
     * Remove dependencias
     *
     * @param \App\Entity\UserDependencia $dependencia
     */
    public function removeDependencia(\App\Entity\UserDependencia $dependencia)
    {
        $this->dependencias->removeElement($dependencia);
    }

    /**
     * Get dependencias
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getDependencias()
    {
        return $this->dependencias;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    /**
     * Set razonsocial
     *
     * @param string $razonsocial
     *
     * @return UserOrganizacion
     */
    public function setRazonsocial($razonsocial)
    {
        $this->razonsocial = $razonsocial;

        return $this;
    }

    /**
     * Get razonsocial
     *
     * @return string
     */
    public function getRazonsocial()
    {
        return $this->razonsocial;
    }
}
