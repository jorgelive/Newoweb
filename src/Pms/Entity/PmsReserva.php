<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entidad PmsReserva.
 * Centraliza la información de una reserva maestra.
 * IDs: UUID (Negocio), Strings (Maestros).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_reserva')]
#[ORM\HasLifecycleCallbacks]
class PmsReserva
{
    /** Gestión de Identificador UUID (BINARY 16) */
    use IdTrait;

    /** Gestión de auditoría temporal (DateTimeImmutable) */
    use TimestampTrait;

    /* ======================================================
     * IDENTIFICADORES EXTERNOS (Beds24 / Canales)
     * ====================================================== */

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $beds24MasterId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $beds24BookIdPrincipal = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $referenciaCanal = null;

    /* ======================================================
     * DATOS DEL CLIENTE
     * ====================================================== */

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: "El nombre del cliente es obligatorio.")]
    private ?string $nombreCliente = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    #[Assert\NotBlank(message: "El apellido del cliente es obligatorio.")]
    private ?string $apellidoCliente = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono2 = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\Email(message: "El formato del correo electrónico no es válido.")]
    private ?string $emailCliente = null;

    /* ======================================================
     * RELACIONES MAESTRAS (IDs NATURALES - Strings)
     * ====================================================== */

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroMoneda $moneda = null;

    #[ORM\ManyToOne(targetEntity: MaestroPais::class, inversedBy: 'reservas')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "Debes seleccionar un idioma principal.")]
    private ?MaestroIdioma $idioma = null;

    #[ORM\ManyToOne(targetEntity: PmsChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "Debes asignar un Canal a la reserva.")]
    private ?PmsChannel $channel = null;

    /* ======================================================
     * DETALLES DE ESTANCIA Y MONTOS
     * ====================================================== */

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cantidadAdultos = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cantidadNinos = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $montoTotal = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $comisionTotal = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaLlegada = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaSalida = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $horaLlegadaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaReservaCanal = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaModificacionCanal = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $datosLocked = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nota = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comentariosHuesped = null;

    /* ======================================================
     * COLECCIONES
     * ====================================================== */

    /** @var Collection<int, PmsEventoCalendario> */
    #[ORM\OneToMany(mappedBy: 'reserva', targetEntity: PmsEventoCalendario::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $eventosCalendario;

    /** @var Collection<int, PmsReservaHuesped> */
    #[ORM\OneToMany(mappedBy: 'reserva', targetEntity: PmsReservaHuesped::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $huespedes;

    public function __construct()
    {
        $this->eventosCalendario = new ArrayCollection();
        $this->huespedes = new ArrayCollection();
    }

    /* ======================================================
     * LÓGICA DE NEGOCIO Y MÉTODOS DE BEDS24
     * ====================================================== */

    public function getNombreApellido(): ?string {
        return trim(($this->nombreCliente ?? '') . ' ' . ($this->apellidoCliente ?? '')) ?: null;
    }

    public function getUrlBeds24(): ?string
    {
        $bookId = $this->beds24MasterId ?: $this->beds24BookIdPrincipal;
        if (!$bookId) {
            foreach ($this->eventosCalendario as $evento) {
                foreach ($evento->getBeds24Links() as $link) {
                    $tmp = $link->getBeds24BookId();
                    if ($tmp) { $bookId = (string) $tmp; break 2; }
                }
            }
        }
        return $bookId ? sprintf('https://beds24.com/control2.php?pagetype=bookingedit&bookid=%s', $bookId) : null;
    }

    public function getSyncStatusAggregate(): string
    {
        $allSynced = true; $hasError = false;
        foreach ($this->eventosCalendario as $evento) {
            $status = $evento->getSyncStatus();
            if ($status === 'error') { $hasError = true; break; }
            if ($status !== 'synced' && $status !== 'local') $allSynced = false;
        }
        return $hasError ? 'error' : (!$allSynced ? 'pending' : 'synced');
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getBeds24MasterId(): ?string { return $this->beds24MasterId; }
    public function setBeds24MasterId(?string $val): self { $this->beds24MasterId = $val; return $this; }

    public function getBeds24BookIdPrincipal(): ?string { return $this->beds24BookIdPrincipal; }
    public function setBeds24BookIdPrincipal(?string $val): self { $this->beds24BookIdPrincipal = $val; return $this; }

    public function getReferenciaCanal(): ?string { return $this->referenciaCanal; }
    public function setReferenciaCanal(?string $val): self { $this->referenciaCanal = $val; return $this; }

    public function getNombreCliente(): ?string { return $this->nombreCliente; }
    public function setNombreCliente(?string $val): self { $this->nombreCliente = $val; return $this; }

    public function getApellidoCliente(): ?string { return $this->apellidoCliente; }
    public function setApellidoCliente(?string $val): self { $this->apellidoCliente = $val; return $this; }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $val): self { $this->telefono = $val; return $this; }

    public function getTelefono2(): ?string { return $this->telefono2; }
    public function setTelefono2(?string $val): self { $this->telefono2 = $val; return $this; }

    public function getEmailCliente(): ?string { return $this->emailCliente; }
    public function setEmailCliente(?string $val): self { $this->emailCliente = $val; return $this; }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $val): self { $this->moneda = $val; return $this; }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $val): self { $this->pais = $val; return $this; }

    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $val): self { $this->idioma = $val; return $this; }

    public function getChannel(): ?PmsChannel { return $this->channel; }
    public function setChannel(?PmsChannel $val): self { $this->channel = $val; return $this; }

    public function getCantidadAdultos(): ?int { return $this->cantidadAdultos; }
    public function setCantidadAdultos(?int $val): self { $this->cantidadAdultos = $val; return $this; }

    public function getCantidadNinos(): ?int { return $this->cantidadNinos; }
    public function setCantidadNinos(?int $val): self { $this->cantidadNinos = $val; return $this; }

    public function getMontoTotal(): ?string { return $this->montoTotal; }
    public function setMontoTotal(?string $val): self { $this->montoTotal = $val; return $this; }

    public function getComisionTotal(): ?string { return $this->comisionTotal; }
    public function setComisionTotal(?string $val): self { $this->comisionTotal = $val; return $this; }

    public function getFechaLlegada(): ?DateTimeInterface { return $this->fechaLlegada; }
    public function setFechaLlegada(?DateTimeInterface $val): self { $this->fechaLlegada = $val; return $this; }

    public function getFechaSalida(): ?DateTimeInterface { return $this->fechaSalida; }
    public function setFechaSalida(?DateTimeInterface $val): self { $this->fechaSalida = $val; return $this; }

    public function getHoraLlegadaCanal(): ?string { return $this->horaLlegadaCanal; }
    public function setHoraLlegadaCanal(?string $val): self { $this->horaLlegadaCanal = $val; return $this; }

    public function getFechaReservaCanal(): ?DateTimeInterface { return $this->fechaReservaCanal; }
    public function setFechaReservaCanal(?DateTimeInterface $val): self { $this->fechaReservaCanal = $val; return $this; }

    public function getFechaModificacionCanal(): ?DateTimeInterface { return $this->fechaModificacionCanal; }
    public function setFechaModificacionCanal(?DateTimeInterface $val): self { $this->fechaModificacionCanal = $val; return $this; }

    public function isDatosLocked(): bool { return $this->datosLocked; }
    public function setDatosLocked(bool $val): self { $this->datosLocked = $val; return $this; }

    public function getNota(): ?string { return $this->nota; }
    public function setNota(?string $val): self { $this->nota = $val; return $this; }

    public function getComentariosHuesped(): ?string { return $this->comentariosHuesped; }
    public function setComentariosHuesped(?string $val): self { $this->comentariosHuesped = $val; return $this; }

    /** @return Collection<int, PmsEventoCalendario> */
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

    /** @return Collection<int, PmsReservaHuesped> */
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
        return sprintf('%s [%s]', $this->getNombreApellido() ?? 'Cliente', $this->referenciaCanal ?? 'Local');
    }
}