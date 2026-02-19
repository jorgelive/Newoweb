<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Oweb\Entity\CuentaMovimiento;
use App\Oweb\Entity\TransporteConductor;
use App\Oweb\Entity\UserArea;
use App\Oweb\Entity\UserCuenta;
use App\Oweb\Entity\UserDependencia;
use App\Repository\UserRepository;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad User.
 * * Gestiona la identidad central del sistema, combinando la seguridad de Symfony
 * con las relaciones de negocio de los módulos Oweb y PMS.
 * * Se utiliza una estrategia de Identificadores UUID (BINARY 16) para permitir
 * la coexistencia de sesiones y datos entre el panel moderno y el sistema legacy.
 */
#[ORM\Table(name: 'user')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * Trait para la gestión de ID en formato UUID BINARY(16).
     */
    use IdTrait;

    /**
     * Trait para la gestión automática de createdAt y updatedAt.
     */
    use TimestampTrait;

    /**
     * Identificador de usuario único para el login.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    protected ?string $username = null;

    /**
     * Correo electrónico del usuario, utilizado como identificador principal en Symfony.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    protected ?string $email = null;

    /**
     * Contraseña cifrada del usuario.
     * @var string|null
     */
    #[ORM\Column(type: 'string')]
    protected ?string $password = null;

    /**
     * Listado de roles asignados (JSON).
     * @var array
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * Estado de activación del usuario.
     * @var bool
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    /**
     * Nombre(s) del usuario.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $firstname = null;

    /**
     * Apellido(s) del usuario.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $lastname = null;

    /**
     * Relación con la Dependencia (Oweb).
     * @var UserDependencia|null
     */
    #[ORM\ManyToOne(targetEntity: UserDependencia::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'dependencia_id', referencedColumnName: 'id', nullable: true)]
    protected ?UserDependencia $dependencia = null;

    /**
     * Relación con el Área (Oweb).
     * @var UserArea|null
     */
    #[ORM\ManyToOne(targetEntity: UserArea::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'area_id', referencedColumnName: 'id', nullable: true)]
    protected ?UserArea $area = null;

    /**
     * Relación OneToMany con Cuentas de Usuario.
     * @var Collection<int, UserCuenta>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserCuenta::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cuentas;

    /**
     * Relación OneToMany con Movimientos de Cuenta.
     * @var Collection<int, CuentaMovimiento>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: CuentaMovimiento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $movimientos;

    /**
     * Relación OneToOne con Conductor (Transporte).
     * @var TransporteConductor|null
     */
    #[ORM\OneToOne(mappedBy: 'user', targetEntity: TransporteConductor::class)]
    private ?TransporteConductor $conductor = null;

    /**
     * Constructor de la entidad.
     * Inicializa las colecciones Doctrine y valores por defecto.
     */
    public function __construct()
    {
        $this->cuentas = new ArrayCollection();
        $this->movimientos = new ArrayCollection();
        $this->enabled = true;
        $this->roles = [];

        $this->id = Uuid::v7();
    }

    /*
     * -------------------------------------------------------------------------
     * IMPLEMENTACIÓN DE SEGURIDAD (UserInterface)
     * -------------------------------------------------------------------------
     */

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): string
    {
        return (string) $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function eraseCredentials(): void
    {
        // No se almacenan credenciales en texto plano.
    }

    /*
     * -------------------------------------------------------------------------
     * PROPIEDADES DE PERFIL PERSONALIZADAS
     * -------------------------------------------------------------------------
     */

    public function setFirstname(?string $firstname): self
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setLastname(?string $lastname): self
    {
        $this->lastname = $lastname;
        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    /**
     * Obtiene el nombre completo concatenado.
     * @return string
     */
    public function getFullname(): string
    {
        return trim((string)$this->firstname . ' ' . (string)$this->lastname);
    }

    /**
     * Alias semántico para el nombre completo.
     * @return string
     */
    public function getNombre(): string
    {
        return $this->getFullname();
    }

    /*
     * -------------------------------------------------------------------------
     * GESTIÓN DE RELACIONES (GETTERS / SETTERS / ADDERS)
     * -------------------------------------------------------------------------
     */

    public function setDependencia(?UserDependencia $dependencia): self
    {
        $this->dependencia = $dependencia;
        return $this;
    }

    public function getDependencia(): ?UserDependencia
    {
        return $this->dependencia;
    }

    public function setArea(?UserArea $area): self
    {
        $this->area = $area;
        return $this;
    }

    public function getArea(): ?UserArea
    {
        return $this->area;
    }

    public function addCuenta(UserCuenta $cuenta): self
    {
        if (!$this->cuentas->contains($cuenta)) {
            $this->cuentas[] = $cuenta;
            $cuenta->setUser($this);
        }
        return $this;
    }

    public function removeCuenta(UserCuenta $cuenta): self
    {
        if ($this->cuentas->removeElement($cuenta)) {
            if ($cuenta->getUser() === $this) {
                $cuenta->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, UserCuenta>
     */
    public function getCuentas(): Collection
    {
        return $this->cuentas;
    }

    public function setConductor(?TransporteConductor $conductor): self
    {
        $this->conductor = $conductor;
        if ($conductor && $conductor->getUser() !== $this) {
            $conductor->setUser($this);
        }
        return $this;
    }

    public function getConductor(): ?TransporteConductor
    {
        return $this->conductor;
    }

    public function addMovimiento(CuentaMovimiento $movimiento): self
    {
        if (!$this->movimientos->contains($movimiento)) {
            $this->movimientos[] = $movimiento;
            $movimiento->setUser($this);
        }
        return $this;
    }

    public function removeMovimiento(CuentaMovimiento $movimiento): self
    {
        if ($this->movimientos->removeElement($movimiento)) {
            if ($movimiento->getUser() === $this) {
                $movimiento->setUser(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, CuentaMovimiento>
     */
    public function getMovimientos(): Collection
    {
        return $this->movimientos;
    }

    /**
     * Representación de cadena de la entidad.
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->username;
    }
}