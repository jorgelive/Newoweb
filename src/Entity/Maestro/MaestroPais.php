<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use App\Entity\Trait\TimestampTrait;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsReservaHuesped;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Entidad MaestroPais.
 * Almacena códigos ISO 3166-1 alpha-2 como IDs naturales y mapeos de proveedores.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maestro_pais')]
#[ORM\HasLifecycleCallbacks]
class MaestroPais
{
    public const ID_PERU = 'PE';
    public const DEFAULT_PAIS = self::ID_PERU;

    use TimestampTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null; // ISO 'PE', 'US'...

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    // 🔥 NUEVO: Campo para la Bandera (Emoji)
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    #[Groups(['pax:read'])]
    private ?string $bandera = null;

    // 🔥 NUEVO: Zona horaria (ej: 'America/Lima', 'Europe/Madrid') - Nullable para no romper producción
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $timezone = null;

    // Añade esto en tu clase MaestroPais
    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_default_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroIdioma $idiomaDefault = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigoMc = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigoConsettur = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigoPeruRail = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $prioritario = false;

    /** @var Collection<int, PmsEstablecimiento> */
    #[ORM\OneToMany(mappedBy: 'pais', targetEntity: PmsEstablecimiento::class)]
    private Collection $establecimientos;

    /** @var Collection<int, PmsReserva> */
    #[ORM\OneToMany(mappedBy: 'pais', targetEntity: PmsReserva::class)]
    private Collection $reservas;

    /** @var Collection<int, PmsReservaHuesped> */
    #[ORM\OneToMany(mappedBy: 'pais', targetEntity: PmsReservaHuesped::class)]
    private Collection $huespedes;

    public function __construct(string $id, string $nombre)
    {
        $this->id = strtoupper($id);
        $this->nombre = $nombre;
        $this->establecimientos = new ArrayCollection();
        $this->reservas = new ArrayCollection();
        $this->huespedes = new ArrayCollection();
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    #[Groups(['pax:read'])]
    public function getId(): ?string { return $this->id; }

    #[Groups(['pax:read'])]
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getBandera(): ?string { return $this->bandera; }
    public function setBandera(?string $bandera): self { $this->bandera = $bandera; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $timezone): self { $this->timezone = $timezone; return $this; }

    public function getIdiomaDefault(): ?MaestroIdioma
    {
        return $this->idiomaDefault;
    }

    public function setIdiomaDefault(?MaestroIdioma $idiomaDefault): self
    {
        $this->idiomaDefault = $idiomaDefault;
        return $this;
    }

    public function getCodigoMc(): ?int { return $this->codigoMc; }
    public function setCodigoMc(?int $val): self { $this->codigoMc = $val; return $this; }

    public function getCodigoConsettur(): ?int { return $this->codigoConsettur; }
    public function setCodigoConsettur(?int $val): self { $this->codigoConsettur = $val; return $this; }

    public function getCodigoPeruRail(): ?int { return $this->codigoPeruRail; }
    public function setCodigoPeruRail(?int $val): self { $this->codigoPeruRail = $val; return $this; }

    /** @return Collection<int, PmsEstablecimiento> */
    public function getEstablecimientos(): Collection { return $this->establecimientos; }

    /** @return Collection<int, PmsReserva> */
    public function getReservas(): Collection { return $this->reservas; }

    /** @return Collection<int, PmsReservaHuesped> */
    public function getHuespedes(): Collection { return $this->huespedes; }

    public function isPrioritario(): bool
    {
        return $this->prioritario;
    }

    public function setPrioritario(bool $prioritario): self
    {
        $this->prioritario = $prioritario;
        return $this;
    }

    public function __toString(): string
    {
        // 🔥 Magia visual: Si tiene bandera, la muestra junto al nombre (Ej: "🇵🇪 Perú")
        return ($this->bandera ? $this->bandera . ' ' : '') . $this->nombre;
    }
}