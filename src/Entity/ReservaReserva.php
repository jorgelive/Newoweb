<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaReserva
 *
 * @ORM\Table(name="res_reserva")
 * @ORM\Entity(repositoryClass="App\Repository\ReservaReservaRepository")
 */
class ReservaReserva
{

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=20)
     */
    private $token;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     */
    private $uid;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $enlace;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $descripcion;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $cantidadadultos = 1;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $cantidadninos = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    private $manual = true;

    /**
     * @var \App\Entity\ReservaChanel
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaChanel", inversedBy="reservas")
     * @ORM\JoinColumn(name="chanel_id", referencedColumnName="id", nullable=false)
     */
    protected $chanel;

    /**
     * @var \App\Entity\ReservaUnit
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnit", inversedBy="reservas")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false)
     */
    protected $unit;

    /**
     * @var \App\Entity\ReservaEstado
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaEstado", inversedBy="reservas")
     * @ORM\JoinColumn(name="estado_id", referencedColumnName="id", nullable=false)
     */
    protected $estado;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaDetalle", mappedBy="reserva", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"id" = "ASC"})
     */
    private $detalles;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaImporte", mappedBy="reserva", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"fecha" = "ASC"})
     */
    private $importes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ReservaPago", mappedBy="reserva", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"fecha" = "ASC"})
     */
    private $pagos;

    /**
     * @var \DateTime $fechahorainicio
     *
     * @ORM\Column(type="datetime")
     */
    private $fechahorainicio;

    /**
     * @var \DateTime $fechahorafin
     *
     * @ORM\Column(type="datetime")
     */
    private $fechahorafin;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $modificado;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->detalles = new ArrayCollection();
        $this->pagos = new ArrayCollection();
        $this->importes = new ArrayCollection();
    }

    public function __clone() {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf("%s: %s - %s", $this->getFechahorainicio()->format('Y/m/d'), $this->getChanel()->getNombre(), $this->getNombre()) ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(string $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getResumen(): ?string
    {
        return sprintf('%s %s: %s %s', substr($this->getChanel()->getNombre(), 0, 1), $this->getNombre(), $this->getUnit()->getNombre(), $this->getUnit()->getEstablecimiento()->getNombre());
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;

        return $this;
    }

    public function getEnlace(): ?string
    {
        return $this->enlace;
    }

    public function setEnlace(?string $enlace): self
    {
        $this->enlace = $enlace;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): self
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getCantidadadultos(): ?int
    {
        return $this->cantidadadultos;
    }

    public function setCantidadadultos(int $cantidadadultos): self
    {
        $this->cantidadadultos = $cantidadadultos;

        return $this;
    }

    public function getCantidadninos(): ?int
    {
        return $this->cantidadninos;
    }

    public function setCantidadninos(int $cantidadninos): self
    {
        $this->cantidadninos = $cantidadninos;

        return $this;
    }

    public function getFechahorainicio(): ?\DateTimeInterface
    {
        return $this->fechahorainicio;
    }

    public function setFechahorainicio(\DateTimeInterface $fechahorainicio): self
    {
        $this->fechahorainicio = $fechahorainicio;

        return $this;
    }

    public function getFechahorafin(): ?\DateTimeInterface
    {
        return $this->fechahorafin;
    }

    public function setFechahorafin(\DateTimeInterface $fechahorafin): self
    {
        $this->fechahorafin = $fechahorafin;

        return $this;
    }

    public function isManual(): ?bool
    {
        return $this->manual;
    }

    public function setManual(bool $manual): self
    {
        $this->manual = $manual;

        return $this;
    }

    public function getCreado(): ?\DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(?\DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?\DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(?\DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getChanel(): ?ReservaChanel
    {
        return $this->chanel;
    }

    public function setChanel(?ReservaChanel $chanel): self
    {
        $this->chanel = $chanel;

        return $this;
    }

    public function getUnit(): ?ReservaUnit
    {
        return $this->unit;
    }

    public function setUnit(?ReservaUnit $unit): self
    {
        $this->unit = $unit;

        return $this;
    }

    public function getEstado(): ?ReservaEstado
    {
        return $this->estado;
    }

    public function setEstado(?ReservaEstado $estado): self
    {
        $this->estado = $estado;

        return $this;
    }

    /**
     * @return Collection<int, ReservaDetalle>
     */
    public function getDetalles(): Collection
    {
        return $this->detalles;
    }

    public function addDetalle(ReservaDetalle $detalle): self
    {
        if (!$this->detalles->contains($detalle)) {
            $this->detalles[] = $detalle;
            $detalle->setReserva($this);
        }

        return $this;
    }

    public function removeDetalle(ReservaDetalle $detalle): self
    {
        if ($this->detalles->removeElement($detalle)) {
            // set the owning side to null (unless already changed)
            if ($detalle->getReserva() === $this) {
                $detalle->setReserva(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ReservaImporte>
     */
    public function getImportes(): Collection
    {
        return $this->importes;
    }

    public function addImporte(ReservaImporte $importe): self
    {
        if (!$this->importes->contains($importe)) {
            $this->importes[] = $importe;
            $importe->setReserva($this);
        }

        return $this;
    }

    public function removeImporte(ReservaImporte $importe): self
    {
        if ($this->importes->removeElement($importe)) {
            // set the owning side to null (unless already changed)
            if ($importe->getReserva() === $this) {
                $importe->setReserva(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ReservaPago>
     */
    public function getPagos(): Collection
    {
        return $this->pagos;
    }

    public function addPago(ReservaPago $pago): self
    {
        if (!$this->pagos->contains($pago)) {
            $this->pagos[] = $pago;
            $pago->setReserva($this);
        }

        return $this;
    }

    public function removePago(ReservaPago $pago): self
    {
        if ($this->pagos->removeElement($pago)) {
            // set the owning side to null (unless already changed)
            if ($pago->getReserva() === $this) {
                $pago->setReserva(null);
            }
        }

        return $this;
    }

}