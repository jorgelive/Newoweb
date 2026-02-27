<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Api\Provider\Pms\PmsReservaByLocalizadorProvider;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsReserva.
 * Centraliza la información de una reserva maestra.
 * 🧼 LIMPIA: 100% libre de acoplamientos con el módulo de Mensajes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_reserva')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/client/pax/pms/pms_reserva/{localizador}',
            uriVariables: [
                'localizador' => new Link(
                    fromClass: PmsReserva::class,
                    identifiers: ['localizador']
                ),
            ],
            normalizationContext: ['groups' => ['pax_reserva:read']],
            name: 'pax_get_reserva',
        ),
    ]
)]
class PmsReserva
{
    use IdTrait;
    use LocatorTrait;
    use TimestampTrait;

    /* ======================================================
     * IDENTIFICADORES EXTERNOS
     * ====================================================== */
    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24MasterId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdPrincipal = null;

    /* ======================================================
     * DATOS DEL CLIENTE
     * ====================================================== */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: 'El nombre del cliente es obligatorio.')]
    #[Assert\Length(max: 180)]
    private ?string $nombreCliente = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: 'El apellido del cliente es obligatorio.')]
    #[Assert\Length(max: 180)]
    private ?string $apellidoCliente = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $telefono2 = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\NotBlank(message: 'El email es obligatorio.')]
    #[Assert\Email(message: 'El formato del email no es válido.')]
    #[Assert\Length(max: 150)]
    private ?string $emailCliente = null;

    /* ======================================================
     * RELACIONES MAESTRAS (IDs NATURALES)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'reservas')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsEstablecimiento $establecimiento = null;

    // ✅ NUEVO: El canal principal/ganador de la reserva
    #[ORM\ManyToOne(targetEntity: PmsChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    private ?PmsChannel $channel = null;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $moneda = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class, inversedBy: 'reservas')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: 'El idioma es obligatorio.')]
    private ?MaestroIdioma $idioma = null;

    /* ======================================================
     * DETALLES DE ESTANCIA Y MONTOS
     * ====================================================== */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: 'La cantidad de adultos no puede ser negativa.')]
    private ?int $cantidadAdultos = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: 'La cantidad de niños no puede ser negativa.')]
    private ?int $cantidadNinos = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'El monto total no puede ser negativo.')]
    private ?string $montoTotal = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'La comisión no puede ser negativa.')]
    private ?string $comisionTotal = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'La fecha de llegada es obligatoria.')]
    private ?DateTimeInterface $fechaLlegada = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\NotNull(message: 'La fecha de salida es obligatoria.')]
    #[Assert\GreaterThan(propertyPath: 'fechaLlegada', message: 'La fecha de salida debe ser posterior a la fecha de llegada.')]
    private ?DateTimeInterface $fechaSalida = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $datosLocked = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

    /* ======================================================
     * COLECCIONES Y CAMPOS AGREGADOS (CACHÉ)
     * ====================================================== */
    #[ORM\OneToMany(mappedBy: 'reserva', targetEntity: PmsEventoCalendario::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $eventosCalendario;

    #[ORM\OneToMany(mappedBy: 'reserva', targetEntity: PmsReservaHuesped::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $huespedes;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $canalesAggregate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referenciaCanalAggregate = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $horaLlegadaCanalAggregate = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $primeraFechaReservaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $ultimaFechaModificacionCanal = null;


    public function __construct()
    {
        $this->eventosCalendario = new ArrayCollection();
        $this->huespedes = new ArrayCollection();
        $this->initializeLocator();

        $this->id = Uuid::v7();
    }

    /* ======================================================
     * GETTERS DE SERIALIZACIÓN (API PAX)
     * ====================================================== */

    #[Groups(['pax_reserva:read'])]
    public function getLocalizador(): ?string
    {
        return $this->localizador;
    }

    #[Groups(['pax_reserva:read'])]
    public function getNombreCompleto(): ?string
    {
        return $this->getNombreApellido();
    }

    #[Groups(['pax_reserva:read'])]
    public function getNumeroNoches(): int
    {
        return $this->getNoches();
    }

    #[Groups(['pax_reserva:read'])]
    public function getPaxTotal(): int
    {
        return ($this->cantidadAdultos ?? 0) + ($this->cantidadNinos ?? 0);
    }

    #[Groups(['pax_reserva:read'])]
    public function getNombreHotel(): string
    {
        $evento = $this->eventosCalendario->first();
        if ($evento && $evento->getPmsUnidad()) {
            return $evento->getPmsUnidad()->getEstablecimiento()?->getNombreComercial() ?? 'Hotel por confirmar';
        }
        return 'Pendiente de asignación';
    }

    #[Groups(['pax_reserva:read'])]
    public function getNombreHabitacion(): string
    {
        if ($this->eventosCalendario->isEmpty()) {
            return 'Pendiente';
        }

        $nombres = [];

        foreach ($this->eventosCalendario as $evento) {
            $unidad = $evento->getPmsUnidad();
            if ($unidad && $unidad->getNombre()) {
                $nombres[] = $unidad->getNombre();
            }
        }

        if (empty($nombres)) {
            return 'Habitación estándar';
        }

        $nombres = array_unique($nombres);

        return implode(', ', $nombres);
    }

    /* ======================================================
     * MÉTODOS AUXILIARES Y LÓGICA DE NEGOCIO
     * ====================================================== */


    public function getEstablecimiento(): ?PmsEstablecimiento { return $this->establecimiento; }
    public function setEstablecimiento(?PmsEstablecimiento $val): self { $this->establecimiento = $val; return $this; }

    public function getNombreApellido(): ?string {
        $full = trim(($this->nombreCliente ?? '') . ' ' . ($this->apellidoCliente ?? ''));
        return $full !== '' ? $full : null;
    }

    public function getNoches(): int {
        if (!$this->fechaLlegada || !$this->fechaSalida) return 0;
        return (int) $this->fechaLlegada->diff($this->fechaSalida)->days;
    }

    public function getUrlBeds24(): ?string {
        $bookId = $this->beds24MasterId ?: $this->beds24BookIdPrincipal;
        if (!$bookId) {
            foreach ($this->eventosCalendario as $evento) {
                foreach ($evento->getBeds24Links() as $link) {
                    if ($link->getBeds24BookId()) {
                        $bookId = (string) $link->getBeds24BookId();
                        break 2;
                    }
                }
            }
        }
        return $bookId ? sprintf('https://beds24.com/control2.php?pagetype=bookingedit&bookid=%s', $bookId) : null;
    }

    public function getSyncStatusAggregate(): string {
        $allSynced = true; $hasError = false;
        if ($this->eventosCalendario->isEmpty()) return 'local';
        foreach ($this->eventosCalendario as $evento) {
            $status = $evento->getSyncStatus();
            if ($status === 'error') { $hasError = true; break; }
            if ($status !== 'synced' && $status !== 'local') $allSynced = false;
        }
        return $hasError ? 'error' : (!$allSynced ? 'pending' : 'synced');
    }

    /* ======================================================
     * GETTERS Y SETTERS EXPLÍCITOS
     * ====================================================== */

    #[Groups(['pax_reserva:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getBeds24MasterId(): ?string { return $this->beds24MasterId; }
    public function setBeds24MasterId(?string $val): self { $this->beds24MasterId = $val; return $this; }

    public function getBeds24BookIdPrincipal(): ?string { return $this->beds24BookIdPrincipal; }
    public function setBeds24BookIdPrincipal(?string $val): self { $this->beds24BookIdPrincipal = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getNombreCliente(): ?string { return $this->nombreCliente; }
    public function setNombreCliente(?string $val): self { $this->nombreCliente = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getApellidoCliente(): ?string { return $this->apellidoCliente; }
    public function setApellidoCliente(?string $val): self { $this->apellidoCliente = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $val): self { $this->telefono = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getTelefono2(): ?string { return $this->telefono2; }
    public function setTelefono2(?string $val): self { $this->telefono2 = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getEmailCliente(): ?string { return $this->emailCliente; }
    public function setEmailCliente(?string $val): self { $this->emailCliente = $val; return $this; }

    // ✅ GETTER / SETTER DEL CANAL / campo propagado por listener, ganador si hay directos
    #[Groups(['pax_reserva:read'])]
    public function getChannel(): ?PmsChannel { return $this->channel; }
    public function setChannel(?PmsChannel $val): self { $this->channel = $val; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $val): self { $this->moneda = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $val): self { $this->pais = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $val): self { $this->idioma = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getCantidadAdultos(): ?int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(?int $val): self { $this->cantidadAdultos = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getCantidadNinos(): ?int { return $this->cantidadNinos; }
    public function setCantidadNinos(?int $val): self { $this->cantidadNinos = $val; return $this; }

    public function getMontoTotal(): ?string { return $this->montoTotal; }
    public function setMontoTotal(?string $val): self { $this->montoTotal = $val; return $this; }

    public function getComisionTotal(): ?string { return $this->comisionTotal; }
    public function setComisionTotal(?string $val): self { $this->comisionTotal = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getFechaLlegada(): ?DateTimeInterface { return $this->fechaLlegada; }
    public function setFechaLlegada(?DateTimeInterface $val): self { $this->fechaLlegada = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getFechaSalida(): ?DateTimeInterface { return $this->fechaSalida; }
    public function setFechaSalida(?DateTimeInterface $val): self { $this->fechaSalida = $val; return $this; }

    public function isDatosLocked(): bool { return $this->datosLocked; }
    public function setDatosLocked(bool $val): self { $this->datosLocked = $val; return $this; }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $val): self { $this->nota = $val; return $this; }

    public function getCanalesAggregate(): ?string { return $this->canalesAggregate; }
    public function setCanalesAggregate(?string $val): self { $this->canalesAggregate = $val; return $this; }

    public function getReferenciaCanalAggregate(): ?string { return $this->referenciaCanalAggregate; }
    public function setReferenciaCanalAggregate(?string $val): self { $this->referenciaCanalAggregate = $val; return $this; }

    public function getHoraLlegadaCanalAggregate(): ?string { return $this->horaLlegadaCanalAggregate; }
    public function setHoraLlegadaCanalAggregate(?string $val): self { $this->horaLlegadaCanalAggregate = $val; return $this; }

    public function getPrimeraFechaReservaCanal(): ?DateTimeInterface { return $this->primeraFechaReservaCanal; }
    public function setPrimeraFechaReservaCanal(?DateTimeInterface $val): self { $this->primeraFechaReservaCanal = $val; return $this; }

    public function getUltimaFechaModificacionCanal(): ?DateTimeInterface { return $this->ultimaFechaModificacionCanal; }
    public function setUltimaFechaModificacionCanal(?DateTimeInterface $val): self { $this->ultimaFechaModificacionCanal = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getEventosCalendario(): Collection { return $this->eventosCalendario; }

    public function addEventosCalendario(PmsEventoCalendario $evento): self {
        if (!$this->eventosCalendario->contains($evento)) {
            $this->eventosCalendario->add($evento);
            $evento->setReserva($this);
        }
        return $this;
    }

    public function removeEventosCalendario(PmsEventoCalendario $evento): self {
        if ($this->eventosCalendario->removeElement($evento)) {
            if ($evento->getReserva() === $this) $evento->setReserva(null);
        }
        return $this;
    }

    public function getHuespedes(): Collection { return $this->huespedes; }

    public function addHuesped(PmsReservaHuesped $huesped): self {
        if (!$this->huespedes->contains($huesped)) {
            $this->huespedes->add($huesped);
            $huesped->setReserva($this);
        }
        return $this;
    }

    public function removeHuesped(PmsReservaHuesped $huesped): self {
        if ($this->huespedes->removeElement($huesped)) {
            if ($huesped->getReserva() === $this) $huesped->setReserva(null);
        }
        return $this;
    }

    public function __toString(): string {
        return sprintf('%s - %s (%s)',
            $this->getNombreApellido() ?? 'Huésped Desconocido',
            $this->referenciaCanalAggregate ?? 'Sin Ref.',
            $this->getLocalizador() ?? 'Sin Loc.'
        );
    }
}