<?php

namespace App\Entity;

use App\Entity\ComprobanteComprobanteitem;
use App\Entity\ComprobanteEstado;
use App\Entity\ComprobanteMensaje;
use App\Entity\ComprobanteTipo;
use App\Entity\MaestroMoneda;
use App\Entity\TransporteServiciocontable;
use App\Entity\UserDependencia;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'com_comprobante')]
#[ORM\Entity]
class ComprobanteComprobante
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: UserDependencia::class)]
    #[ORM\JoinColumn(name: 'dependencia_id', referencedColumnName: 'id', nullable: false)]
    private ?UserDependencia $dependencia = null;

    /** @var Collection<int, ComprobanteComprobanteitem> */
    #[ORM\OneToMany(mappedBy: 'comprobante', targetEntity: ComprobanteComprobanteitem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $comprobanteitems;

    /** @var Collection<int, TransporteServiciocontable> */
    #[ORM\OneToMany(mappedBy: 'comprobante', targetEntity: TransporteServiciocontable::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $serviciocontables;

    #[ORM\Column(type: 'string', length: 250, nullable: true)]
    private ?string $nota = null;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    // Decimales como string para evitar pérdida de precisión
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $neto = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $impuesto = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $total = null;

    #[ORM\ManyToOne(targetEntity: ComprobanteTipo::class)]
    #[ORM\JoinColumn(name: 'tipo_id', referencedColumnName: 'id', nullable: false)]
    private ?ComprobanteTipo $tipo = null;

    #[ORM\Column(type: 'string', length: 6, nullable: true)]
    private ?string $serie = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $documento = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechaemision = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $url = null;

    /** @var Collection<int, ComprobanteMensaje> */
    #[ORM\OneToMany(mappedBy: 'comprobante', targetEntity: ComprobanteMensaje::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mensajes;

    /** @var Collection<int, ComprobanteComprobante> */
    #[ORM\OneToMany(mappedBy: 'original', targetEntity: ComprobanteComprobante::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $dependientes;

    #[ORM\ManyToOne(targetEntity: ComprobanteComprobante::class, inversedBy: 'dependientes')]
    private ?ComprobanteComprobante $original = null;

    #[ORM\ManyToOne(targetEntity: ComprobanteEstado::class)]
    #[ORM\JoinColumn(name: 'estado_id', referencedColumnName: 'id', nullable: false)]
    private ?ComprobanteEstado $estado = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __construct()
    {
        $this->comprobanteitems  = new ArrayCollection();
        $this->serviciocontables = new ArrayCollection();
        $this->dependientes      = new ArrayCollection();
        $this->mensajes          = new ArrayCollection();
    }

    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newItems = new ArrayCollection();
            foreach ($this->comprobanteitems as $ci) {
                $n = clone $ci;
                $n->setComprobante($this);
                $newItems->add($n);
            }
            $this->comprobanteitems = $newItems;

            $newServs = new ArrayCollection();
            foreach ($this->serviciocontables as $sc) {
                $n = clone $sc;
                $n->setComprobante($this);
                $newServs->add($n);
            }
            $this->serviciocontables = $newServs;

            $this->mensajes     = new ArrayCollection();
            $this->dependientes = new ArrayCollection();
        }
    }

    public function __toString(): string
    {
        if (!empty($this->getDocumento()) && !empty($this->getSerie())) {
            return sprintf(
                '%s-%s-%s',
                $this->getTipo()?->getCodigo(),
                $this->getSerie(),
                str_pad((string) $this->getDocumento(), 5, '0', STR_PAD_LEFT)
            );
        }
        return sprintf('Id: %s.', $this->getId() ?? '');
    }

    public function getId(): ?int { return $this->id; }

    public function setNota(?string $nota): self { $this->nota = $nota; return $this; }
    public function getNota(): ?string { return $this->nota; }

    public function setNeto(?string $neto): self { $this->neto = $neto; return $this; }
    public function getNeto(): ?string { return $this->neto; }

    public function setImpuesto(?string $impuesto): self { $this->impuesto = $impuesto; return $this; }
    public function getImpuesto(): ?string { return $this->impuesto; }

    public function setTotal(?string $total): self { $this->total = $total; return $this; }
    public function getTotal(): ?string { return $this->total; }

    public function setSerie(?string $serie): self { $this->serie = $serie; return $this; }
    public function getSerie(): ?string { return $this->serie; }

    public function setDocumento(?string $documento): self { $this->documento = $documento; return $this; }
    public function getDocumento(): ?string { return $this->documento; }

    public function getSeriedocumento(): string
    {
        return sprintf('%s-%s', $this->serie ?? '', $this->documento ?? '');
    }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }

    public function setDependencia(UserDependencia $dependencia): self { $this->dependencia = $dependencia; return $this; }
    public function getDependencia(): ?UserDependencia { return $this->dependencia; }

    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }
    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }

    public function setTipo(ComprobanteTipo $tipo): self { $this->tipo = $tipo; return $this; }
    public function getTipo(): ?ComprobanteTipo { return $this->tipo; }

    public function setEstado(?ComprobanteEstado $estado): self { $this->estado = $estado; return $this; }
    public function getEstado(): ?ComprobanteEstado { return $this->estado; }

    public function setFechaemision(?DateTimeInterface $fechaemision): self { $this->fechaemision = $fechaemision; return $this; }
    public function getFechaemision(): ?DateTimeInterface { return $this->fechaemision; }

    public function setUrl(?string $url): self { $this->url = $url; return $this; }
    public function getUrl(): ?string { return $this->url; }

    public function addDependiente(ComprobanteComprobante $dependiente): self
    {
        $dependiente->setOriginal($this);
        $this->dependientes->add($dependiente);
        return $this;
    }
    public function removeDependiente(ComprobanteComprobante $dependiente): void
    {
        $this->dependientes->removeElement($dependiente);
    }
    /** @return Collection<int, ComprobanteComprobante> */
    public function getDependientes(): Collection { return $this->dependientes; }

    public function setOriginal(?ComprobanteComprobante $original): self { $this->original = $original; return $this; }
    public function getOriginal(): ?ComprobanteComprobante { return $this->original; }

    public function addMensaje(ComprobanteMensaje $mensaje): self
    {
        $mensaje->setComprobante($this);
        $this->mensajes->add($mensaje);
        return $this;
    }
    public function removeMensaje(ComprobanteMensaje $mensaje): void
    {
        $this->mensajes->removeElement($mensaje);
    }
    /** @return Collection<int, ComprobanteMensaje> */
    public function getMensajes(): Collection { return $this->mensajes; }

    public function addServiciocontable(TransporteServiciocontable $serviciocontable): self
    {
        $serviciocontable->setComprobante($this);
        $this->serviciocontables->add($serviciocontable);
        return $this;
    }
    public function removeServiciocontable(TransporteServiciocontable $serviciocontable): bool
    {
        return $this->serviciocontables->removeElement($serviciocontable);
    }
    /** @return Collection<int, TransporteServiciocontable> */
    public function getServiciocontables(): Collection { return $this->serviciocontables; }

    public function addComprobanteitem(ComprobanteComprobanteitem $comprobanteitem): self
    {
        $comprobanteitem->setComprobante($this);
        $this->comprobanteitems->add($comprobanteitem);
        return $this;
    }
    public function removeComprobanteitem(ComprobanteComprobanteitem $comprobanteitem): bool
    {
        return $this->comprobanteitems->removeElement($comprobanteitem);
    }
    /** @return Collection<int, ComprobanteComprobanteitem> */
    public function getComprobanteitems(): Collection { return $this->comprobanteitems; }
}
