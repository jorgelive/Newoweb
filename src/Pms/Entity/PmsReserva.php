<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use App\Entity\MaestroPais;
use App\Entity\MaestroIdioma;

#[ORM\Entity]
#[ORM\Table(name: 'pms_reserva')]
class PmsReserva
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // --- IDENTIFICADORES Y RELACIONES B24 ---

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24MasterId = null;

    // Beds24: fallback cuando masterId es null. Corresponde al campo "id" del payload /api/v2/bookings.
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdPrincipal = null;

    // Beds24: aquí guardamos el apiReference (ej: HMZCX8HZ2K). Booking/Airbnb: aquí va la referencia del canal.
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $referenciaCanal = null;

    // ----------------------------------------

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $nombreCliente = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $apellidoCliente = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono2 = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $datosLocked = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cantidadAdultos = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cantidadNinos = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
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
    private ?MaestroIdioma $idioma = null;

    #[ORM\OneToMany(
        mappedBy: 'reserva',
        targetEntity: PmsEventoCalendario::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $eventosCalendario;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    public function __construct()
    {
        $this->eventosCalendario = new ArrayCollection();
    }

    // ========================================================================
    //                 LINKS VIRTUALES (Booking, Airbnb, Beds24)
    // ========================================================================

    /**
     * Con el modelo nuevo (Beds24Link), el map vive en el link.
     * Tomamos el primer map disponible en cualquier evento.
     */
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

    /**
     * Con el modelo nuevo, ya no existe getBeds24BookId() en el evento.
     * Buscamos un bookId primero en:
     *  - beds24MasterId
     *  - beds24BookIdPrincipal
     *  - primer link con beds24BookId
     *  - si el link no tiene, usamos originLink->beds24BookId (caso mirrors)
     */
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

    // --- GETTERS Y SETTERS ---

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

    /**
     * Setter de compatibilidad para Symfony Form / Sonata.
     * Mantiene la consistencia usando add/remove (no reemplaza la colección a lo bruto).
     */
    public function setEventosCalendario(iterable $eventosCalendario): self
    {
        // Remover los que ya no están
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

        // Agregar nuevos
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
}