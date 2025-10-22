<?php

namespace App\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * cuenta
 */
#[ORM\Table(name: 'use_cuenta')]
#[ORM\Entity]
class UserCuenta
{
    /**
     * @var integer
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(name: 'nombre', type: 'string', length: 100)]
    #[Assert\NotBlank]
    private $nombre;

    /**
     * @var string
     */
    #[ORM\Column(name: 'password', type: 'string', length: 100)]
    #[Assert\NotBlank]
    private $password;

    /**
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    /**
     * @var UserUser
     */
    #[ORM\ManyToOne(targetEntity: 'UserUser', inversedBy: 'cuentas')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private $user;

    /**
     * @var UserCuentatipo
     */
    #[ORM\ManyToOne(targetEntity: 'UserCuentatipo', inversedBy: 'cuentas')]
    #[ORM\JoinColumn(name: 'cuentatipo_id', referencedColumnName: 'id', nullable: false)]
    private $cuentatipo;

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
     * @return UserCuenta
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
     * Set password
     *
     * @param string $password
     * @return UserCuenta
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get password
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set creado
     *
     * @param DateTime $creado
     * @return UserCuenta
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;

        return $this;
    }

    /**
     * Get creado
     *
     * @return DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     *
     * @param DateTime $modificado
     * @return UserCuenta
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;

        return $this;
    }

    /**
     * Get modificado
     *
     * @return DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set user
     *
     * @param UserUser $user
     * @return UserCuenta
     */
    public function setUser(UserUser $user = null)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return UserUser
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set cuentatipo
     *
     * @param UserCuentatipo $cuentatipo
     * @return UserCuenta
     */
    public function setCuentatipo(UserCuentatipo $cuentatipo = null)
    {
        $this->cuentatipo = $cuentatipo;

        return $this;
    }

    /**
     * Get cuentatipo
     *
     * @return UserCuentatipo
     */
    public function getCuentatipo()
    {
        return $this->cuentatipo;
    }

}
