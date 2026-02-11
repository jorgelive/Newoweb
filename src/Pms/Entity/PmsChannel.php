<?php

namespace App\Pms\Entity;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Entidad PmsChannel.
 * Primary Key: id (ID Natural: airbnb, booking, directo, etc).
 */
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
    public const CANAL_PAGO_TOTAL   = [self::CODIGO_BOOKING, self::CODIGO_VRBO];

    /**
     * El ID es el código string.
     * Importante: Al ser ID natural, NO lleva GeneratedValue.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $id = null;

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

    #[Groups(['pax_reserva:read'])]
    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    #[Groups(['pax_reserva:read'])]
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