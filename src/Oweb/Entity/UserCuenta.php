<?php

namespace App\Oweb\Entity;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad UserCuenta.
 * Gestiona las credenciales y perfiles de cuenta vinculados a los usuarios del sistema.
 */
#[ORM\Table(name: 'use_cuenta')]
#[ORM\Entity]
class UserCuenta
{
    /**
     * Identificador autoincremental de la cuenta de usuario.
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
     * Fecha de creación del registro.
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * Fecha de la última modificación del registro.
     * @var DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    /**
     * Relación ManyToOne con la entidad User.
     * Se especifica BINARY(16) para el mapeo con el ID UUID.
     * @var User
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'cuentas')]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16)'
    )]
    private $user;

    /**
     * @var UserCuentatipo
     */
    #[ORM\ManyToOne(targetEntity: UserCuentatipo::class, inversedBy: 'cuentas')]
    #[ORM\JoinColumn(name: 'cuentatipo_id', referencedColumnName: 'id', nullable: false)]
    private $cuentatipo;

    /**
     * Representación textual de la cuenta.
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    /**
     * Get id
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set nombre
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
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }

    /**
     * Set password
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
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * Set creado
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
     * @return DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
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
     * @return DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Set user
     * @param User|null $user
     * @return UserCuenta
     */
    public function setUser(User $user = null)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get user
     * @return User|null
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set cuentatipo
     * @param UserCuentatipo|null $cuentatipo
     * @return UserCuenta
     */
    public function setCuentatipo(UserCuentatipo $cuentatipo = null)
    {
        $this->cuentatipo = $cuentatipo;
        return $this;
    }

    /**
     * Get cuentatipo
     * @return UserCuentatipo|null
     */
    public function getCuentatipo()
    {
        return $this->cuentatipo;
    }
}