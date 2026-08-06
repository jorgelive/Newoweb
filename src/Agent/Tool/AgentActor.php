<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Entity\User;
use App\Security\Roles;

/**
 * Quién está preguntando. Unifica los tres orígenes en un solo concepto para que las
 * herramientas y el registro no tengan que saber si la pregunta llegó por el panel, por
 * WhatsApp o por una OTA.
 *
 * **El huésped también es un actor con rol** (`ROLE_HUESPED`), no un caso especial sin
 * permisos. La diferencia no es que tenga menos derechos por serlo, sino que sus
 * herramientas están acotadas a SU reserva a través de `contextoId`.
 *
 * Ver docs/Mensajeria.md §12.
 */
final readonly class AgentActor
{
    /**
     * @param list<string> $roles
     * @param string|null  $contextoTipo `pms_reserva`, `manual`… El contexto de la conversación.
     * @param string|null  $contextoId   La reserva concreta. Para un huésped es la frontera de
     *                                   lo que puede consultar; para un empleado suele ser null.
     */
    private function __construct(
        public ?User $usuario,
        public string $origen,
        public array $roles,
        public ?string $contextoTipo = null,
        public ?string $contextoId = null,
    ) {}

    /** Un miembro del equipo con sesión en el panel. */
    public static function delPanel(User $usuario): self
    {
        return new self($usuario, 'panel', $usuario->getRoles());
    }

    /**
     * Un miembro del equipo escribiendo desde su móvil, identificado por su número.
     *
     * El teléfono identifica pero no autentica: no es una contraseña. Para este negocio es
     * proporcionado —el activo son fechas de reservas— pero por eso las operaciones
     * destructivas piden PIN (ver NivelRiesgo) y todo queda registrado.
     */
    public static function delEquipoPorChat(User $usuario, string $origen): self
    {
        return new self($usuario, $origen, $usuario->getRoles());
    }

    /** Quien escribe por el chat sin ser del equipo. Acotado a su propia reserva. */
    public static function huesped(string $origen, ?string $contextoTipo, ?string $contextoId): self
    {
        return new self(null, $origen, [Roles::HUESPED], $contextoTipo, $contextoId);
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

    /**
     * @param list<string> $roles Vacío ⇒ basta con ser un actor cualquiera.
     */
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

    /** Para los logs: quién preguntó, sin volcar la entidad entera. */
    public function etiqueta(): string
    {
        return $this->usuario !== null
            ? sprintf('%s (%s)', $this->usuario->getUserIdentifier(), $this->origen)
            : sprintf('huésped %s (%s)', $this->contextoId ?? '¿?', $this->origen);
    }
}
