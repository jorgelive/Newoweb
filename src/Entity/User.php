<?php

namespace App\Entity;

use App\Oweb\Entity\CuentaMovimiento;
use App\Oweb\Entity\TransporteConductor;
use App\Oweb\Entity\UserArea;
use App\Oweb\Entity\UserCuenta;
use App\Oweb\Entity\UserDependencia;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User Entity.
 *
 * Implementación de seguridad nativa de Symfony.
 * NOTA: Se ha eliminado toda compatibilidad con serialización antigua de SonataUserBundle.
 * Los roles ahora se manejan estrictamente como arrays JSON nativos.
 */
#[ORM\Table(name: 'user')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    /**
     * CAMPOS DE SEGURIDAD
     */

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    protected ?string $username = null;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    protected ?string $email = null;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string')]
    protected ?string $password = null;

    /**
     * Roles del usuario.
     * Almacenados como JSON en base de datos, pero manipulados como array nativo en PHP.
     *
     * @var array
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    /**
     * CAMPOS PERSONALIZADOS
     */

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $firstname = null;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $lastname = null;

    /**
     * RELACIONES
     */

    /**
     * @var UserDependencia|null
     */
    #[ORM\ManyToOne(targetEntity: UserDependencia::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'dependencia_id', referencedColumnName: 'id', nullable: true)]
    protected ?UserDependencia $dependencia = null;

    /**
     * @var UserArea|null
     */
    #[ORM\ManyToOne(targetEntity: UserArea::class, inversedBy: 'users')]
    #[ORM\JoinColumn(name: 'area_id', referencedColumnName: 'id', nullable: true)]
    protected ?UserArea $area = null;

    /**
     * @var Collection<int, UserCuenta>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserCuenta::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cuentas;

    /**
     * @var Collection<int, CuentaMovimiento>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: CuentaMovimiento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $movimientos;

    /**
     * @var TransporteConductor|null
     */
    #[ORM\OneToOne(mappedBy: 'user', targetEntity: TransporteConductor::class)]
    private ?TransporteConductor $conductor = null;

    /**
     * @var \DateTimeInterface|null
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @var \DateTimeInterface|null
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * Constructor de la entidad.
     * Inicializa las colecciones y establece valores por defecto.
     */
    public function __construct()
    {
        $this->cuentas = new ArrayCollection();
        $this->movimientos = new ArrayCollection();
        $this->enabled = true;
        $this->roles = [];
    }

    /**
     * GETTERS Y SETTERS BÁSICOS
     */

    public function getId(): ?int
    {
        return $this->id;
    }

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

    /**
     * Lógica de Seguridad (UserInterface / PasswordAuthenticatedUserInterface)
     */

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
        return (string) $this->email;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * GETTERS Y SETTERS PERSONALIZADOS
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

    public function getFullname(): string
    {
        return trim((string)$this->firstname . ' ' . (string)$this->lastname);
    }

    public function getNombre(): string
    {
        return $this->getFullname();
    }

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

    public function getMovimientos(): Collection
    {
        return $this->movimientos;
    }

    public function __toString(): string
    {
        return (string) $this->username;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}