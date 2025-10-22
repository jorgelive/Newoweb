<?php

namespace App\Entity;

use App\Entity\ComprobanteTipoproductoservicio;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'com_productoservicio')]
#[ORM\Entity]
class ComprobanteProductoservicio
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $codigo = null;

    #[ORM\Column(type: 'string', length: 15)]
    private ?string $codigosunat = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    #[ORM\ManyToOne(targetEntity: ComprobanteTipoproductoservicio::class)]
    #[ORM\JoinColumn(name: 'tipoproductoservicio_id', referencedColumnName: 'id', nullable: false)]
    private ?ComprobanteTipoproductoservicio $tipoproductoservicio = null;

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    public function getId(): ?int { return $this->id; }

    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getNombre(): ?string { return $this->nombre; }

    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }
    public function getCodigo(): ?string { return $this->codigo; }

    public function setCodigosunat(?string $codigosunat): self { $this->codigosunat = $codigosunat; return $this; }
    public function getCodigosunat(): ?string { return $this->codigosunat; }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }

    public function setTipoproductoservicio(ComprobanteTipoproductoservicio $tipoproductoservicio): self
    { $this->tipoproductoservicio = $tipoproductoservicio; return $this; }

    public function getTipoproductoservicio(): ?ComprobanteTipoproductoservicio
    { return $this->tipoproductoservicio; }
}
