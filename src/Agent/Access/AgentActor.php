<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Entity\User;
use App\Security\Roles;

/**
 * Implementación estándar de {@see ActorInterface}.
 *
 * Unifica los orígenes para que las skills y el registro no tengan que saber si la pregunta
 * llegó por el panel, por WhatsApp o por una OTA.
 *
 * **El huésped también es un actor con rol** (`ROLE_HUESPED`), no un caso especial sin
 * permisos. Lo que lo distingue no es tener menos derechos: es que sus skills están acotadas
 * a SU reserva a través del contexto.
 *
 * Ver docs/Mensajeria.md §11.
 */
final readonly class AgentActor implements ActorInterface
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        public ?User $usuario,
        private string $origen,
        private array $roles,
        private ?string $contextoTipo = null,
        private ?string $contextoId = null,
    ) {}

    /** Un miembro del equipo con sesión en el panel. */
    public static function delPanel(User $usuario): self
    {
        return new self($usuario, 'panel', $usuario->getRoles());
    }

    /**
     * Un miembro del equipo escribiendo desde su móvil, identificado por su número.
     *
     * El teléfono identifica pero no autentica. Para este negocio es proporcionado —el activo
     * son fechas de reservas— y por eso el control se escala con el daño en {@see NivelRiesgo}
     * en vez de blindar el canal entero.
     */
    public static function delEquipoPorChat(User $usuario, string $origen, ?string $tipo = null, ?string $id = null): self
    {
        return new self($usuario, $origen, $usuario->getRoles(), $tipo, $id);
    }

    /** Quien escribe por el chat sin ser del equipo. Acotado a su propia reserva. */
    public static function huesped(string $origen, ?string $contextoTipo, ?string $contextoId): self
    {
        return new self(null, $origen, [Roles::HUESPED], $contextoTipo, $contextoId);
    }

    public function roles(): array
    {
        return $this->roles;
    }

    public function origen(): string
    {
        return $this->origen;
    }

    public function contextoTipo(): ?string
    {
        return $this->contextoTipo;
    }

    public function contextoId(): ?string
    {
        return $this->contextoId;
    }

    public function esDelEquipo(): bool
    {
        return $this->usuario !== null;
    }

    public function tieneRol(string $rol): bool
    {
        // SUPER_ADMIN abre todas las puertas, igual que en el resto del sistema.
        return in_array(Roles::SUPER_ADMIN, $this->roles, true)
            || in_array($rol, $this->roles, true);
    }

    public function tieneAlguno(array $roles): bool
    {
        if ($roles === []) {
            return true;
        }

        foreach ($roles as $rol) {
            if ($this->tieneRol($rol)) {
                return true;
            }
        }

        return false;
    }

    public function etiqueta(): string
    {
        return $this->usuario !== null
            ? sprintf('%s (%s)', $this->usuario->getUserIdentifier(), $this->origen)
            : sprintf('huésped %s (%s)', $this->contextoId ?? '¿?', $this->origen);
    }
}
