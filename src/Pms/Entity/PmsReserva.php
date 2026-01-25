<?php

namespace App\Pms\Entity;

use App\Entity\MaestroIdioma;
use App\Entity\MaestroPais;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

// Importante para la relación

#[ORM\Entity]
#[ORM\Table(name: 'pms_reserva')]
class PmsReserva
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24MasterId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdPrincipal = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $referenciaCanal = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: "El nombre del cliente es obligatorio.")]
    private ?string $nombreCliente = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: "El apellido del cliente es obligatorio.")]
    private ?string $apellidoCliente = null;

    // --- Se eliminaron $documento y $tipoDocumento ---

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono2 = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $datosLocked = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: "No puede haber adultos negativos.")]
    private ?int $cantidadAdultos = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\PositiveOrZero(message: "No puede haber niños negativos.")]
    private ?int $cantidadNinos = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\Email(message: "El formato del correo electrónico no es válido.")]
    private ?string $emailCliente = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comentariosHuesped = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $horaLlegadaCanal = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaLlegada = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaSalida = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaReservaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaModificacionCanal = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "Debes asignar un Canal a la reserva.")]
    private ?PmsChannel $channel = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $montoTotal = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $comisionTotal = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: "Debes seleccionar un idioma principal.")]
    private ?MaestroIdioma $idioma = null;

    #[ORM\OneToMany(
        mappedBy: 'reserva',
        targetEntity: PmsEventoCalendario::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Valid]
    private Collection $eventosCalendario;

    /**
     * NAMELIST: Colección de huéspedes para el Pre Check-in
     */
    #[ORM\OneToMany(
        mappedBy: 'reserva',
        targetEntity: PmsReservaHuesped::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $huespedes;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->eventosCalendario = new ArrayCollection();
        $this->huespedes = new ArrayCollection();
    }

    // =========================================================================
    // LÓGICA DE NEGOCIO Y URLS PRESERVADA AL 100%
    // =========================================================================

    public function getUnidadBeds24MapPrincipal(): ?PmsUnidadBeds24Map
    {
        if ($this->eventosCalendario->count() === 0) {
            return null;
        }

        foreach ($this->eventosCalendario as $evento) {
            if (!$evento instanceof PmsEventoCalendario) {
                continue;
            }

            foreach ($evento->getBeds24Links() as $link) {
                if ($link instanceof PmsEventoBeds24Link) {
                    $map = $link->getUnidadBeds24Map();
                    if ($map instanceof PmsUnidadBeds24Map) {
                        return $map;
                    }
                }
            }
        }

        return null;
    }

    public function getUrlBooking(): ?string
    {
        if (!$this->referenciaCanal) return null;
        if ($this->channel && stripos($this->channel->getNombre(), 'booking') === false) return null;

        $hotelId = $this->getUnidadBeds24MapPrincipal()?->getChannelPropId();
        if (!$hotelId) return null;

        return sprintf(
            'https://admin.booking.com/hotel/bookings/booking/%s?hotel_id=%s',
            $this->referenciaCanal,
            $hotelId
        );
    }

    public function getUrlAirbnb(): ?string
    {
        if (!$this->referenciaCanal) return null;
        if ($this->channel && stripos($this->channel->getNombre(), 'airbnb') === false) return null;

        return sprintf(
            'https://www.airbnb.com/hosting/reservations/details/%s',
            $this->referenciaCanal
        );
    }

    public function getUrlBeds24(): ?string
    {
        $bookId = $this->beds24MasterId ?: $this->beds24BookIdPrincipal;

        if (!$bookId && $this->eventosCalendario->count() > 0) {
            foreach ($this->eventosCalendario as $evento) {
                if (!$evento instanceof PmsEventoCalendario) {
                    continue;
                }

                foreach ($evento->getBeds24Links() as $link) {
                    if (!$link instanceof PmsEventoBeds24Link) {
                        continue;
                    }

                    $tmp = $link->getBeds24BookId();
                    if (($tmp === null || $tmp === '') && $link->getOriginLink() !== null) {
                        $tmp = $link->getOriginLink()?->getBeds24BookId();
                    }

                    if ($tmp !== null && $tmp !== '') {
                        $bookId = (string) $tmp;
                        break 2;
                    }
                }
            }
        }

        if (!$bookId) return null;

        return sprintf(
            'https://beds24.com/control2.php?pagetype=bookingedit&bookid=%s',
            $bookId
        );
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    public function getId(): ?int { return $this->id; }

    public function getBeds24MasterId(): ?string { return $this->beds24MasterId; }
    public function setBeds24MasterId(?string $beds24MasterId): self { $this->beds24MasterId = $beds24MasterId; return $this; }

    public function getBeds24BookIdPrincipal(): ?string { return $this->beds24BookIdPrincipal; }
    public function setBeds24BookIdPrincipal(?string $beds24BookIdPrincipal): self { $this->beds24BookIdPrincipal = $beds24BookIdPrincipal; return $this; }

    public function getReferenciaCanal(): ?string { return $this->referenciaCanal; }
    public function setReferenciaCanal(?string $referenciaCanal): self { $this->referenciaCanal = $referenciaCanal; return $this; }

    public function getNombreCliente(): ?string { return $this->nombreCliente; }
    public function setNombreCliente(?string $nombreCliente): self { $this->nombreCliente = $nombreCliente; return $this; }

    public function getApellidoCliente(): ?string { return $this->apellidoCliente; }
    public function setApellidoCliente(?string $apellidoCliente): self { $this->apellidoCliente = $apellidoCliente; return $this; }

    public function getNombreApellido(): ?string
    {
        $nombre = trim((string) ($this->getNombreCliente() ?? ''));
        $apellido = trim((string) ($this->apellidoCliente ?? ''));

        $full = trim($nombre . ' ' . $apellido);
        return $full !== '' ? $full : null;
    }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $telefono): self { $this->telefono = $telefono; return $this; }

    public function getTelefono2(): ?string { return $this->telefono2; }
    public function setTelefono2(?string $telefono2): self { $this->telefono2 = $telefono2; return $this; }

    public function getEmailCliente(): ?string { return $this->emailCliente; }
    public function setEmailCliente(?string $emailCliente): self { $this->emailCliente = $emailCliente; return $this; }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $nota): self { $this->nota = $nota; return $this; }

    public function getComentariosHuesped(): ?string { return $this->comentariosHuesped; }
    public function setComentariosHuesped(?string $comentariosHuesped): self { $this->comentariosHuesped = $comentariosHuesped; return $this; }

    public function getHoraLlegadaCanal(): ?string { return $this->horaLlegadaCanal; }
    public function setHoraLlegadaCanal(?string $horaLlegadaCanal): self { $this->horaLlegadaCanal = $horaLlegadaCanal; return $this; }

    public function getFechaLlegada(): ?DateTimeInterface { return $this->fechaLlegada; }
    public function setFechaLlegada(?DateTimeInterface $fechaLlegada): self { $this->fechaLlegada = $fechaLlegada; return $this; }

    public function getFechaSalida(): ?DateTimeInterface { return $this->fechaSalida; }
    public function setFechaSalida(?DateTimeInterface $fechaSalida): self { $this->fechaSalida = $fechaSalida; return $this; }

    public function getFechaReservaCanal(): ?DateTimeInterface { return $this->fechaReservaCanal; }
    public function setFechaReservaCanal(?DateTimeInterface $fechaReservaCanal): self { $this->fechaReservaCanal = $fechaReservaCanal; return $this; }

    public function getFechaModificacionCanal(): ?DateTimeInterface { return $this->fechaModificacionCanal; }
    public function setFechaModificacionCanal(?DateTimeInterface $fechaModificacionCanal): self { $this->fechaModificacionCanal = $fechaModificacionCanal; return $this; }

    public function getChannel(): ?PmsChannel { return $this->channel; }
    public function setChannel(?PmsChannel $channel): self
    {
        if ($channel === null) {
            return $this;
        }
        $this->channel = $channel;
        return $this;
    }

    public function getMontoTotal(): ?string { return $this->montoTotal; }
    public function setMontoTotal(?string $montoTotal): self { $this->montoTotal = $montoTotal; return $this; }

    public function getComisionTotal(): ?string { return $this->comisionTotal; }
    public function setComisionTotal(?string $comisionTotal): self { $this->comisionTotal = $comisionTotal; return $this; }

    public function getEventosCalendario(): Collection { return $this->eventosCalendario; }

    public function setEventosCalendario(iterable $eventosCalendario): self
    {
        foreach ($this->eventosCalendario as $existente) {
            if (!$existente instanceof PmsEventoCalendario) {
                continue;
            }
            $enNuevo = false;
            foreach ($eventosCalendario as $nuevo) {
                if ($nuevo === $existente) {
                    $enNuevo = true;
                    break;
                }
            }
            if (!$enNuevo) {
                $this->removeEventoCalendario($existente);
            }
        }

        foreach ($eventosCalendario as $evento) {
            if ($evento instanceof PmsEventoCalendario) {
                $this->addEventoCalendario($evento);
            }
        }

        return $this;
    }

    public function addEventoCalendario(PmsEventoCalendario $evento): self {
        if (!$this->eventosCalendario->contains($evento)) {
            $this->eventosCalendario->add($evento);
            $evento->setReserva($this);
        }
        return $this;
    }
    public function removeEventoCalendario(PmsEventoCalendario $evento): self {
        if ($this->eventosCalendario->removeElement($evento)) {
            if ($evento->getReserva() === $this) {
                $evento->setReserva(null);
            }
        }
        return $this;
    }

    public function getCreated(): ?DateTimeInterface { return $this->created; }
    public function getUpdated(): ?DateTimeInterface { return $this->updated; }

    public function __toString(): string
    {
        $ref = $this->referenciaCanal ? " [$this->referenciaCanal]" : '';
        $nombre = trim(($this->nombreCliente ?? '') . ' ' . ($this->apellidoCliente ?? ''));
        $cliente = $nombre !== '' ? $nombre : 'Cliente';
        return sprintf('%s%s', $cliente, $ref);
    }

    public function isDatosLocked(): ?bool { return $this->datosLocked; }
    public function setDatosLocked(?bool $datosLocked): self { $this->datosLocked = $datosLocked; return $this; }

    public function getCantidadAdultos(): ?int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(?int $cantidadAdultos): self { $this->cantidadAdultos = $cantidadAdultos; return $this; }

    public function getCantidadNinos(): ?int { return $this->cantidadNinos; }
    public function setCantidadNinos(?int $cantidadNinos): self { $this->cantidadNinos = $cantidadNinos; return $this; }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $idioma): self
    {
        if ($idioma === null) {
            return $this;
        }
        $this->idioma = $idioma;
        return $this;
    }

    /**
     * @return Collection<int, PmsReservaHuesped>
     */
    public function getHuespedes(): Collection
    {
        return $this->huespedes;
    }

    public function addHuesped(PmsReservaHuesped $huesped): self
    {
        if (!$this->huespedes->contains($huesped)) {
            $this->huespedes->add($huesped);
            $huesped->setReserva($this);
        }
        return $this;
    }

    public function removeHuesped(PmsReservaHuesped $huesped): self
    {
        if ($this->huespedes->removeElement($huesped)) {
            if ($huesped->getReserva() === $this) {
                $huesped->setReserva(null);
            }
        }
        return $this;
    }

    public function getSyncStatusAggregate(): string
    {
        $allSynced = true;
        $hasError = false;

        foreach ($this->getEventosCalendario() as $evento) {
            if (!$evento->isSynced()) {
                $allSynced = false;
                foreach ($evento->getBeds24Links() as $link) {
                    foreach ($link->getQueues() as $queue) {
                        if ($queue->getStatus() === PmsBookingsPushQueue::STATUS_FAILED) {
                            $hasError = true;
                            break 3;
                        }
                    }
                }
            }
        }

        if ($hasError) return 'error';
        if (!$allSynced) return 'pending';
        return 'synced';
    }
}