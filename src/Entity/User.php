<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Repository\UserRepository;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use App\EventListener\UserIntegrityListener;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad User.
 * * Gestiona la identidad central del sistema con la seguridad de Symfony.
 * * Identificadores UUID (BINARY 16). Hasta el 17/09/2026 también enlazaba con el módulo Oweb (panel
 * Sonata heredado); ver el aviso junto a `$lastname`.
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
    /** @var list<string> Los roles literales de la columna; la jerarquía la resuelve security.yaml. */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    /**
     * Estado de activación del usuario.
     * @var bool
     */
    #[ORM\Column(type: 'boolean')]
    protected bool $enabled = true;

    /**
     * ¿Es quien cobra por defecto? (recepción).
     *
     * Sólo tiene sentido junto a `ROLE_COBRADOR`. Cuando se registra un pago sin decir quién
     * lo recibió, se atribuye a esta persona: en la práctica casi todo el efectivo lo cobra
     * recepción, y obligar a repetirlo en cada pago era fricción sin información.
     *
     * ⚠️ Un defecto, no una regla: el importe atribuido SIEMPRE se enseña antes de confirmar
     * (`cobrado_por` en la previsualización de `RegistrarPagoSkill`, campo visible en el
     * panel), porque un cobrador equivocado y silencioso descuadra dos cajas.
     *
     * Se espera **uno solo**. Con varios marcados se toma el primero por orden de nombre, que
     * es determinista pero arbitrario: es un dato mal puesto, no un caso a soportar.
     */
    #[ORM\Column(name: 'es_cobrador_principal', type: 'boolean', options: ['default' => false])]
    protected bool $esCobradorPrincipal = false;

    /**
     * Móvil desde el que escribe al sistema. **Es su identificador en el chat.**
     *
     * Cuando llega un WhatsApp, el remitente es un número: sin esto no hay forma de saber si
     * lo manda un huésped o alguien del equipo, y todos entraban por la puerta del huésped
     * ({@see \App\Agent\Access\AgentActor::huesped()}), acotados a una reserva. Con el número
     * registrado se puede construir el actor del equipo
     * ({@see \App\Agent\Access\AgentActor::delEquipoPorChat()}), que ya existía sin nadie que
     * lo usara.
     *
     * **Formato: sólo dígitos, con código de país y sin `+`** (`51987654321`), el mismo que
     * `PmsReserva::$telefono`. Lo garantiza {@see UserIntegrityListener} vía
     * {@see \App\Service\Phone\PhoneSanitizer}, así que no hay que teclearlo limpio: se
     * normaliza al guardar, se escriba como se escriba.
     *
     * ⚠️ `unique`: dos personas con el mismo número serían indistinguibles justo cuando hay
     * que decidir quién manda. MySQL admite varios NULL, así que quien no lo tenga no estorba.
     *
     * ⚠️ El teléfono IDENTIFICA pero NO AUTENTICA (una SIM se clona, un número se suplanta).
     * Por eso el control del agente se escala con el daño en `NivelRiesgo` en lugar de confiar
     * en el canal — ver el docblock de `AgentActor::delEquipoPorChat()`.
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true, unique: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefono = null;

    /**
     * Quién queda asignada por defecto a la limpieza de cada estancia nueva.
     *
     * Mismo patrón que {@see self::$esCobradorPrincipal}, y por el mismo motivo: el defecto es
     * un DATO y no una constante con el nombre de una persona dentro. El día que quien limpia
     * hoy tome otro camino se marca a otra en el panel, sin tocar código ni migrar nada.
     *
     * Lo aplica `PmsLimpiezaAsignacionListener` al crear el evento. Es un DEFECTO, no una
     * regla: después se le añaden o se le quitan personas a mano, y una casita grande puede
     * acabar con dos.
     *
     * Se espera **una sola** marcada. Con varias se toma la primera por nombre, que es
     * determinista pero arbitrario: es un dato mal puesto, no un caso a soportar. Con NINGUNA,
     * las estancias nuevas nacen sin asignar — y entonces no le salen a nadie de campo, que es
     * el fallo seguro.
     */
    #[ORM\Column(name: 'es_limpieza_por_defecto', type: 'boolean', options: ['default' => false])]
    private bool $esLimpiezaPorDefecto = false;

    public function isEsLimpiezaPorDefecto(): bool { return $this->esLimpiezaPorDefecto; }
    public function setEsLimpiezaPorDefecto(bool $val): self { $this->esLimpiezaPorDefecto = $val; return $this; }

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

    /*
     * ⚠️ Aquí estaban cinco relaciones con el módulo Oweb —dependencia, área, cuentas, movimientos de
     * cuenta y conductor—, retirado el 17/09/2026. Ningún código fuera de Oweb las usaba. Las columnas
     * `dependencia_id` y `area_id` de la tabla `user` se eliminan por migración; las tablas de Oweb con
     * sus datos siguen en la base. El código, en la etiqueta git `oweb-final`.
     */

    /**
     * Constructor de la entidad: valores por defecto.
     */
    public function __construct()
    {
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

    /**
     * @param list<string> $roles
     */
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

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    /**
     * Se puede escribir como venga («+51 987 654 321», «987654321»): `UserIntegrityListener`
     * lo normaliza a dígitos con código de país antes de que llegue a la BD.
     */
    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;
        return $this;
    }

    public function isEsCobradorPrincipal(): bool
    {
        return $this->esCobradorPrincipal;
    }

    public function setEsCobradorPrincipal(bool $esCobradorPrincipal): self
    {
        $this->esCobradorPrincipal = $esCobradorPrincipal;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        $identificador = (string) $this->username;

        // ⚠️ `UserInterface::getUserIdentifier()` declara `non-empty-string`, y con razón: una
        // cadena vacía no da error — recorre el firewall como un identificador más y no casa con
        // nadie. Una fila sin `username` está rota; que lo diga aquí y no tres capas más abajo.
        if ($identificador === '') {
            throw new \LogicException(sprintf(
                'El usuario %s no tiene `username`, y sin él no se puede autenticar.',
                (string) $this->getId(),
            ));
        }

        return $identificador;
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

    /**
     * Representación de cadena de la entidad.
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->username;
    }
}