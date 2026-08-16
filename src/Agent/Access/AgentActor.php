<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Entity\User;
use App\Message\Contract\VinculoComercial;
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
        private ?string $conversacionId = null,
        private VinculoComercial $vinculo = VinculoComercial::Ninguno,
        private RestriccionCanal $restriccion = RestriccionCanal::Ninguna,
        private array $dominios = [],
    ) {}

    /**
     * {@inheritDoc}
     *
     * ⚠️ **Vacío significa SIN ACOTAR, no «sin acceso a nada».** Es deliberado y es lo contrario
     * de lo que pide la intuición.
     *
     * La alternativa —vacío = ningún negocio— convierte cualquier olvido en un actor mudo: se
     * queda sin una sola skill de negocio, el agente contesta que no puede ayudar, y no hay
     * error en ningún log que lo delate. Con esta semántica, un actor todavía sin poblar se
     * comporta **exactamente como antes de que existieran los dominios**, que es el fallo seguro
     * mientras el modelo se termina de enchufar.
     *
     * El precio es que un olvido queda permisivo en vez de restrictivo. Se acepta porque el
     * dominio **no es un permiso**: lo que se puede hacer lo siguen decidiendo los roles, y un
     * actor de más en un catálogo no abre ningún dato de nadie.
     */
    public function dominios(): array
    {
        return $this->dominios;
    }

    /**
     * Un miembro del equipo con sesión en el panel.
     *
     * ⚠️ `$rolesEfectivos` NO es opcional por comodidad: `User::getRoles()` devuelve la
     * columna literal, sin la jerarquía de `security.yaml`. Pasar `null` deja fuera los roles
     * heredados, y con eso quien tiene `ROLE_RESERVAS_DELETE` no pasa un control que pida
     * `ROLE_RESERVAS_WRITE` —aunque en el panel pueda hacer justo eso—. Usa
     * {@see AgentActorFactory}, que los expande; el `null` es sólo para actores sintéticos de
     * prueba, donde los roles se fijan a mano.
     *
     * @param list<string>|null $rolesEfectivos
     */
    public static function delPanel(User $usuario, ?array $rolesEfectivos = null): self
    {
        return new self($usuario, 'panel', $rolesEfectivos ?? $usuario->getRoles());
    }

    /**
     * Un miembro del equipo escribiendo desde su móvil, identificado por su número.
     *
     * El teléfono identifica pero no autentica. Para este negocio es proporcionado —el activo
     * son fechas de reservas— y por eso el control se escala con el daño en {@see NivelRiesgo}
     * en vez de blindar el canal entero.
     */
    public static function delEquipoPorChat(
        User $usuario,
        string $origen,
        ?string $tipo = null,
        ?string $id = null,
        ?array $rolesEfectivos = null,
        ?string $conversacionId = null
    ): self {
        return new self($usuario, $origen, $rolesEfectivos ?? $usuario->getRoles(), $tipo, $id, $conversacionId);
    }

    /** Quien escribe por el chat sin ser del equipo. Acotado a su propia reserva. */
    public static function huesped(
        string $origen,
        ?string $contextoTipo,
        ?string $contextoId,
        ?string $conversacionId = null,
        VinculoComercial $vinculo = VinculoComercial::Cliente,
        RestriccionCanal $restriccion = RestriccionCanal::Ninguna,
        array $dominios = []
    ): self {
        return new self(
            null,
            $origen,
            [Roles::HUESPED],
            $contextoTipo,
            $contextoId,
            $conversacionId,
            $vinculo,
            $restriccion,
            $dominios
        );
    }

    /**
     * Quien pregunta sin ser todavía nadie: un número desconocido pidiendo precios.
     *
     * **Nace sin contexto y eso no es un olvido.** Es lo que lo distingue del huésped y lo que
     * le cierra las skills acotadas a una reserva sin necesidad de enumerarlas. Por eso no
     * acepta parámetros de contexto: pasárselos sería convertirlo en un huésped a medias.
     *
     * Es siempre DIRECTO: quien escribe a nuestro número no viene por una OTA, así que a su
     * cotización no se le aplica ningún porcentaje de servicio.
     *
     * Sigue naciendo sin contexto. Lo que sí recibe es el HILO
     * ({@see ActorInterface::conversacionId()}), que no es lo mismo: sin él no podía escalar
     * —su único camino para no ser un callejón sin salida— y con él no se le abre ninguna
     * skill acotada, porque todas miran el contexto y ese sigue en `null`.
     */
    public static function prospecto(string $origen, ?string $conversacionId = null, array $dominios = []): self
    {
        return new self(
            null,
            $origen,
            [Roles::PROSPECTO],
            null,
            null,
            $conversacionId,
            VinculoComercial::Ninguno,
            RestriccionCanal::Ninguna,
            $dominios
        );
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

    public function conversacionId(): ?string
    {
        return $this->conversacionId;
    }

    public function vinculo(): VinculoComercial
    {
        return $this->vinculo;
    }

    public function restriccion(): RestriccionCanal
    {
        return $this->restriccion;
    }

    public function esDelEquipo(): bool
    {
        return $this->usuario !== null;
    }

    public function usuario(): ?User
    {
        return $this->usuario;
    }

    public function tieneRol(string $rol): bool
    {
        // SUPER_ADMIN abre todas las puertas, igual que en el resto del sistema.
        return in_array(Roles::SUPER_ADMIN, $this->roles, true)
            || in_array($rol, $this->roles, true);
    }

    /** @param list<string> $roles */
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

    /** ¿Pregunta sin ser todavía cliente de nada? */
    public function esProspecto(): bool
    {
        return $this->usuario === null && $this->tieneRol(Roles::PROSPECTO);
    }

    public function etiqueta(): string
    {
        if ($this->usuario !== null) {
            return sprintf('%s (%s)', $this->usuario->getUserIdentifier(), $this->origen);
        }

        return $this->esProspecto()
            ? sprintf('prospecto (%s)', $this->origen)
            : sprintf('huésped %s (%s)', $this->contextoId ?? '¿?', $this->origen);
    }
}
