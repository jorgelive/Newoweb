<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\User;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Algo que un huésped pidió para su estancia, y que alguien tiene que dejar puesto.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * «¿Pueden dejarme una plancha?», «¿hay secador?», «¿me suben una estufa?». El agente
 * contestaba —a veces escalando, a veces con un «tomamos nota»— y **la nota no existía en
 * ninguna parte**. 37 peticiones en el histórico: 12 de secador, 12 de plancha, 7 de estufa, 5
 * de frazadas, 1 de almohadas. Cada una dependía de que una persona se acordara.
 *
 * Y no valía ninguno de los sitios que ya había:
 *
 * - `PmsReserva::$nota` y `PmsEventoCalendario::$comentariosHuesped` **se sincronizan a
 *   Beds24**, así que escribir ahí manda el apunte a la OTA.
 * - `escalar_al_equipo` manda un WhatsApp y marca el hilo. Sirve para «contéstame ahora», pero
 *   **no sobrevive hasta el día de la llegada**: el aviso se lee a las tres de la tarde y la
 *   plancha hace falta el jueves.
 *
 * Faltaba el concepto: una petición **pegada a la estancia**, que se vea el día que alguien
 * prepara la casita.
 *
 * ── Cuelga de la ESTANCIA, no de la reserva ─────────────────────────────────
 * Porque quien la cumple prepara una casita concreta un día concreto. Una reserva con dos
 * casitas tiene dos preparaciones distintas, y «dejar plancha» sólo le toca a una.
 *
 * ── Un solo paso: hecha o no ────────────────────────────────────────────────
 * No hay estados intermedios ni «no se puede» a propósito. Lo que se necesitaba era que se VEA
 * y que alguien pueda decir «ya está»; inventar un flujo de aprobación antes de saber cómo se
 * usa es adivinar. Si aparece la necesidad de rechazar una, se añade entonces.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_peticion')]
#[ORM\Index(columns: ['evento_id', 'efectuada_at'], name: 'idx_peticion_evento')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'PmsPeticion',
    operations: [
        // Las peticiones de una estancia. Limpieza entra aquí sin ver el resto del PMS.
        new GetCollection(
            uriTemplate: '/peticiones',
            security: "is_granted('" . Roles::LIMPIEZA . "') or is_granted('" . Roles::CUSTOMER_SUPPORT . "') or is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        // Marcarla. Es lo único que se puede cambiar desde fuera: el texto lo escribe quien la
        // pide, y reescribirlo sería cambiar lo que el huésped dijo.
        new Patch(
            uriTemplate: '/peticiones/{id}',
            security: "is_granted('" . Roles::LIMPIEZA . "') or is_granted('" . Roles::CUSTOMER_SUPPORT . "')",
            denormalizationContext: ['groups' => ['pms_peticion:write']]
        ),
    ],
    routePrefix: '/pms',
    normalizationContext: ['groups' => ['pms_peticion:read']],
)]
class PmsPeticion
{
    use IdTrait;
    use TimestampTrait;

    /** La estancia a la que se le deja puesto. */
    #[ORM\ManyToOne(targetEntity: PmsEventoCalendario::class)]
    #[ORM\JoinColumn(name: 'evento_id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['pms_peticion:read'])]
    private ?PmsEventoCalendario $evento = null;

    /**
     * Lo que pidió, en una línea y con sus palabras.
     *
     * Corto a propósito: es una lista para mirar de un vistazo mientras se prepara una casita,
     * no el historial del chat. Si hace falta el contexto, está el hilo.
     */
    #[ORM\Column(type: 'string', length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Groups(['pms_peticion:read'])]
    private string $texto = '';

    /** Quién la anotó. `null` = la anotó el agente, que es el caso normal. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $creadaPor = null;

    /**
     * De qué conversación salió, para poder volver al chat sin buscarla.
     *
     * Se guarda el uuid a pelo y no una relación: `MessageConversation` es de otro módulo y el
     * PMS no le cuelga nada. Es el mismo desacople deliberado que usa el escalado.
     */
    #[ORM\Column(name: 'conversacion_id', type: 'string', length: 36, nullable: true)]
    #[Groups(['pms_peticion:read'])]
    private ?string $conversacionId = null;

    /** Cuándo alguien comprobó que estaba puesta. `null` = pendiente. */
    #[ORM\Column(name: 'efectuada_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['pms_peticion:read', 'pms_peticion:write'])]
    private ?DateTimeImmutable $efectuadaAt = null;

    /** Quién la dio por hecha. Se rellena solo al marcarla. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'efectuada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $efectuadaPor = null;

    public function getEvento(): ?PmsEventoCalendario { return $this->evento; }
    public function setEvento(?PmsEventoCalendario $evento): self { $this->evento = $evento; return $this; }

    public function getTexto(): string { return $this->texto; }
    public function setTexto(string $texto): self { $this->texto = $texto; return $this; }

    public function getCreadaPor(): ?User { return $this->creadaPor; }
    public function setCreadaPor(?User $creadaPor): self { $this->creadaPor = $creadaPor; return $this; }

    public function getConversacionId(): ?string { return $this->conversacionId; }
    public function setConversacionId(?string $conversacionId): self { $this->conversacionId = $conversacionId; return $this; }

    public function getEfectuadaAt(): ?DateTimeImmutable { return $this->efectuadaAt; }
    public function setEfectuadaAt(?DateTimeImmutable $efectuadaAt): self { $this->efectuadaAt = $efectuadaAt; return $this; }

    public function getEfectuadaPor(): ?User { return $this->efectuadaPor; }
    public function setEfectuadaPor(?User $efectuadaPor): self { $this->efectuadaPor = $efectuadaPor; return $this; }

    #[Groups(['pms_peticion:read'])]
    public function isPendiente(): bool { return $this->efectuadaAt === null; }

    /** Quién la dio por hecha, para la lista. El objeto entero no hace falta ahí. */
    #[Groups(['pms_peticion:read'])]
    public function getEfectuadaPorNombre(): ?string
    {
        return $this->efectuadaPor?->getUserIdentifier();
    }

    public function __toString(): string
    {
        return $this->texto;
    }
}
