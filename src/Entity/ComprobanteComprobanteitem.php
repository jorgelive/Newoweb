<?php

namespace App\Entity;

use App\Entity\ComprobanteComprobante;
use App\Entity\ComprobanteProductoservicio;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'com_comprobanteitem')]
#[ORM\Entity]
class ComprobanteComprobanteitem
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ComprobanteComprobante::class, inversedBy: 'comprobanteitems')]
    #[ORM\JoinColumn(name: 'comprobante_id', referencedColumnName: 'id', nullable: false)]
    private ?ComprobanteComprobante $comprobante = null;

    #[ORM\Column(type: 'integer')]
    private int $cantidad;

    #[ORM\ManyToOne(targetEntity: ComprobanteProductoservicio::class)]
    #[ORM\JoinColumn(name: 'productoservicio_id', referencedColumnName: 'id', nullable: false)]
    private ?ComprobanteProductoservicio $productoservicio = null;

    // Importante: decimal en Doctrine -> string en PHP (evita pérdida de precisión)
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: false)]
    private ?string $unitario = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        if (!empty($this->getProductoservicio())) {
            return sprintf(
                '%s x %s (%s)',
                $this->getProductoservicio()->getNombre(),
                (string) $this->getCantidad(),
                (string) $this->getUnitario()
            );
        }
        return '';
    }

    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }
    }

    public function getId(): ?int { return $this->id; }

    public function setUnitario(?string $unitario): self { $this->unitario = $unitario; return $this; }
    public function getUnitario(): ?string { return $this->unitario; }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }

    public function setComprobante(ComprobanteComprobante $comprobante): self { $this->comprobante = $comprobante; return $this; }
    public function getComprobante(): ?ComprobanteComprobante { return $this->comprobante; }

    public function setProductoservicio(ComprobanteProductoservicio $productoservicio): self { $this->productoservicio = $productoservicio; return $this; }
    public function getProductoservicio(): ?ComprobanteProductoservicio { return $this->productoservicio; }

    public function setCantidad(int $cantidad): self { $this->cantidad = $cantidad; return $this; }
    public function getCantidad(): int { return $this->cantidad; }
}
