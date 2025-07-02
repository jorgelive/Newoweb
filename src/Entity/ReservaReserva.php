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

    // Lista de prefijos de países hispanohablantes
    private array $prefijosEspanol = [
        '+54', '+591', '+56', '+57', '+506', '+53', '+593',
        '+503', '+34', '+502', '+504', '+52', '+505', '+507',
        '+595', '+51', '+598', '+58',
        '+1-787', '+1-939', '+1-809', '+1-829', '+1-849' // Puerto Rico y Rep. Dominicana
    ];

    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private ?string $token = null;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private ?string $uid = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $nombre = null;

    /**
     * @ORM\Column(type="string", length=20, nullable=true)
     */
    private ?string $calificacion = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $enlace = null;

    /**
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private ?string $telefono = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $nota = null;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $cantidadadultos = 1;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $cantidadninos = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $manual = true;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaChannel", inversedBy="reservas")
     * @ORM\JoinColumn(name="channel_id", referencedColumnName="id", nullable=false)
     */
    private ?ReservaChannel $channel;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaUnitnexo", inversedBy="reservas")
     * @ORM\JoinColumn(name="unitnexo_id", referencedColumnName="id", nullable=true)
     */
    private ?ReservaUnitnexo $unitnexo;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaUnit", inversedBy="reservas")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false)
     */
    private ?ReservaUnit $unit;

    /**
     * @ORM\ManyToOne(targetEntity="ReservaEstado", inversedBy="reservas")
     * @ORM\JoinColumn(name="estado_id", referencedColumnName="id", nullable=false)
     */
    private ?ReservaEstado $estado;

    /**
     * @ORM\OneToMany(targetEntity="ReservaDetalle", mappedBy="reserva", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"id" = "ASC"})
     */
    private Collection $detalles;

    /**
     * @ORM\OneToMany(targetEntity="ReservaImporte", mappedBy="reserva", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private Collection $importes;

    /**
     * @ORM\OneToMany(targetEntity="ReservaPago", mappedBy="reserva", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"fecha" = "ASC"})
     */
    private Collection $pagos;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $fechahorainicio;

    /**
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $fechahorafin;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $creado;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $modificado;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->detalles = new ArrayCollection();
        $this->pagos = new ArrayCollection();
        $this->importes = new ArrayCollection();
    }

    public function __clone()
    {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $this->setToken(mt_rand());
        }
    }

    public function __toString(): string
    {
        return sprintf("%s: %s - %s", $this->getFechahorainicio()->format('Y/m/d'), $this->getChannel()->getNombre(), $this->getNombre()) ?? sprintf("Id: %s.", $this->getId()) ?? '';
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

        $distintivo = '';

        if(!empty($this->getUnitnexo()) && !empty($this->getUnitnexo()->getDistintivo())){
            $distintivo = '(' . $this->getUnitnexo()->getDistintivo() . ')';
        }

        if($this->isManual() && $this->getChannel()->getId() != ReservaChannel::DB_VALOR_DIRECTO){
            $canal = substr($this->getChannel()->getNombre(), 0, 1) . '(D)' . $distintivo;
        }else{
            $canal = substr($this->getChannel()->getNombre(), 0, 1). $distintivo;
        }

        $calificacion = $this->getCalificacion();

        if(!empty($calificacion)){
            return sprintf('%s x %s | %s | %s | %s', $canal, $this->getCantidadadultos() + $this->getCantidadninos(), $this->getNombre(), $calificacion, $this->getUnit()->getNombre());
        }
        return sprintf('%s x %s | %s | %s', $canal, $this->getCantidadadultos() + $this->getCantidadninos(), $this->getNombre(), $this->getUnit()->getNombre());
    }

    public function getPrimerNombre(): ?string
    {
        $palabras = explode(' ', trim($this->getNombre()));

        return $palabras[0];

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

    public function getCalificacion(): ?string
    {
        return $this->calificacion;
    }

    public function setCalificacion(?string $calificacion): self
    {
        $this->calificacion = $calificacion;

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

    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }


    public function getTelefonoNormalizado(): ?string
    {
        return preg_replace('/[\s\-\(\)]+/', '', $this->getTelefono());
    }

    public function getIdiomaTelefono() : string
    {
        // Ordenar prefijos de mayor a menor longitud para evitar errores
        usort($this->prefijosEspanol, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        // Comparar número con cada prefijo
        foreach ($this->prefijosEspanol as $prefijo) {
            $prefijoSinGuiones = str_replace('-', '', $prefijo);
            if (strpos($this->getTelefonoNormalizado(), $prefijoSinGuiones) === 0) {
                //return $prefijo;
                return 'es';
            }
        }
        return 'en';
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function setNota(?string $nota): self
    {
        $this->nota = $nota;

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

    public function getFechahorainicio(): ?\DateTime
    {
        return $this->fechahorainicio;
    }

    public function setFechahorainicio(?\DateTime $fechahorainicio): self
    {
        $this->fechahorainicio = $fechahorainicio;

        return $this;
    }

    public function getFechahorafin(): ?\DateTime
    {
        return $this->fechahorafin;
    }

    public function setFechahorafin(?\DateTime $fechahorafin): self
    {
        $this->fechahorafin = $fechahorafin;

        return $this;
    }

    public function isManual(): ?bool
    {
        return $this->manual;
    }

    public function setManual(?bool $manual): self
    {
        $this->manual = $manual;

        return $this;
    }

    public function getCreado(): ?\DateTime
    {
        return $this->creado;
    }

    public function setCreado(?\DateTime $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?\DateTime
    {
        return $this->modificado;
    }

    public function setModificado(?\DateTime $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getChannel(): ?ReservaChannel
    {
        return $this->channel;
    }

    public function setChannel(?ReservaChannel $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getUnitnexo(): ?ReservaUnitnexo
    {
        return $this->unitnexo;
    }

    public function setUnitnexo(?ReservaUnitnexo $unitnexo): self
    {
        $this->unitnexo = $unitnexo;

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

    public function getDetalles(): Collection
    {
        return $this->detalles;
    }

    public function addDetalle(ReservaDetalle $detalle): self
    {
        if(!$this->detalles->contains($detalle)) {
            $this->detalles[] = $detalle;
            $detalle->setReserva($this);
        }

        return $this;
    }

    public function removeDetalle(ReservaDetalle $detalle): self
    {
        if($this->detalles->removeElement($detalle)) {
            // set the owning side to null (unless already changed)
            if($detalle->getReserva() === $this) {
                $detalle->setReserva(null);
            }
        }

        return $this;
    }

    public function getImportes(): Collection
    {
        return $this->importes;
    }

    public function addImporte(ReservaImporte $importe): self
    {
        if(!$this->importes->contains($importe)) {
            $this->importes[] = $importe;
            $importe->setReserva($this);
        }

        return $this;
    }

    public function removeImporte(ReservaImporte $importe): self
    {
        if($this->importes->removeElement($importe)) {
            // set the owning side to null (unless already changed)
            if($importe->getReserva() === $this) {
                $importe->setReserva(null);
            }
        }

        return $this;
    }

    public function getPagos(): Collection
    {
        return $this->pagos;
    }

    public function addPago(ReservaPago $pago): self
    {
        if(!$this->pagos->contains($pago)) {
            $this->pagos[] = $pago;
            $pago->setReserva($this);
        }

        return $this;
    }

    public function removePago(ReservaPago $pago): self
    {
        if($this->pagos->removeElement($pago)) {
            // set the owning side to null (unless already changed)
            if($pago->getReserva() === $this) {
                $pago->setReserva(null);
            }
        }

        return $this;
    }

}
