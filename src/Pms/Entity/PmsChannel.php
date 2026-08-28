<?php

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsChannel.
 * Primary Key: id (ID Natural: airbnb, booking, directo, etc).
 */
#[ApiResource(
    operations: [
        // Solo lectura: alimenta el selector de canal del calendario SPA (Vue).
        new GetCollection(
            normalizationContext: ['groups' => ['pms_channel:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
        ),
    ],
    routePrefix: '/pms',
    order: ['orden' => 'ASC'],
    paginationEnabled: false,
)]
#[ORM\Entity]
#[ORM\Table(name: 'pms_channel')]
#[ORM\HasLifecycleCallbacks]
class PmsChannel
{
    use TimestampTrait;

    public const CODIGO_DIRECTO     = 'directo';
    public const CODIGO_AIRBNB      = 'airbnb';
    public const CODIGO_VRBO        = 'vrbo';
    public const CODIGO_BOOKING     = 'booking';
    public const CANAL_PAGO_TOTAL   = [self::CODIGO_AIRBNB, self::CODIGO_VRBO];

    /**
     * Orígenes en los que la reserva es NUESTRA: no hay plataforma de por medio.
     *
     * Es lo que decide si al huésped se le puede escribir a su correo de siempre o hay que
     * pasar por el relay de la OTA. Estaba escrita **tres veces** —dos en `Beds24SendEnqueuer`,
     * una con `strtolower` y otra sin él— que es justo la deriva de doble criterio que este
     * repo tiene documentada.
     *
     * La cadena vacía cuenta como propia: una reserva sin canal es una que se cargó a mano.
     */
    public const array ORIGENES_PROPIOS = [self::CODIGO_DIRECTO, 'manual', 'web', ''];

    /**
     * Canales cuyo chat NO acepta imágenes.
     *
     * Booking transporta sólo texto, así que a un huésped suyo hay que decirle que la captura
     * del pago la mande por WhatsApp. Es un dato del CANAL, y por eso vive aquí y no en
     * `FinMedioCobro`: el catálogo de cobro lo comparten el PMS y las cotizaciones, y no tiene
     * por qué saber qué es Booking. Estuvo metido dentro de las notas de Yape y Plin, y el
     * resultado era que a un huésped que había reservado **directo** se le hablaba del chat de
     * una plataforma por la que no vino.
     *
     * Airbnb no está: su chat sí admite imágenes. Se enumera lo que falla, no lo que funciona.
     */
    public const array CHAT_SIN_IMAGENES = [self::CODIGO_BOOKING];

    /** ¿Se le puede mandar una captura por el chat de este canal? */
    public function chatAdmiteImagenes(): bool
    {
        return !in_array(strtolower(trim((string) $this->getId())), self::CHAT_SIN_IMAGENES, true);
    }

    /** ¿Este origen es una plataforma que se interpone entre el huésped y nosotros? */
    public static function esDePlataforma(?string $origen): bool
    {
        return !in_array(strtolower(trim((string) $origen)), self::ORIGENES_PROPIOS, true);
    }

    /**
     * El ID es el código string.
     * Importante: Al ser ID natural, NO lleva GeneratedValue.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $id = null;

    /** @var Collection<int, PmsEventoCalendario> */
    #[ORM\OneToMany(mappedBy: 'channel', targetEntity: PmsEventoCalendario::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $eventosCalendario;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $esExterno = false;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $esDirecto = false;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    private ?string $color = null;

    /**
     * ID que usa Beds24 para identificar este canal en su API v2.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $beds24ChannelId = null;

    /**
     * Prioridad de visualización (menor número sale primero).
     */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    public function __construct(?string $id = null)
    {
        if ($id) {
            $this->id = $id;
        }
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS
     * -------------------------------------------------------------------------
     */

    #[Groups(['pax_reserva:read', 'pms_channel:read', 'pms_evento:read', 'pms_reserva:read'])]
    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /** @return Collection<int, PmsEventoCalendario> */
    public function getEventosCalendario(): Collection { return $this->eventosCalendario; }

    public function addEventosCalendario(PmsEventoCalendario $evento): self {
        if (!$this->eventosCalendario->contains($evento)) {
            $this->eventosCalendario->add($evento);
            $evento->setChannel($this);
        }
        return $this;
    }

    public function removeEventosCalendario(PmsEventoCalendario $evento): self {
        if ($this->eventosCalendario->removeElement($evento)) {
            if ($evento->getChannel() === $this) $evento->setChannel(null);
        }
        return $this;
    }

    #[Groups(['pax_reserva:read', 'pms_channel:read', 'pms_evento:read', 'pms_reserva:read'])]
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getEsExterno(): bool
    {
        return $this->esExterno;
    }

    public function setEsExterno(bool $esExterno): self
    {
        $this->esExterno = $esExterno;
        return $this;
    }

    public function getEsDirecto(): bool
    {
        return $this->esDirecto;
    }

    public function setEsDirecto(bool $esDirecto): self
    {
        $this->esDirecto = $esDirecto;
        return $this;
    }

    #[Groups(['pms_channel:read', 'pms_evento:read', 'pms_reserva:read'])]
    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getBeds24ChannelId(): ?string
    {
        return $this->beds24ChannelId;
    }

    public function setBeds24ChannelId(?string $beds24ChannelId): self
    {
        $this->beds24ChannelId = $beds24ChannelId;
        return $this;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;
        return $this;
    }

    public function __toString(): string
    {
        return $this->nombre ?? (string) $this->id;
    }

}