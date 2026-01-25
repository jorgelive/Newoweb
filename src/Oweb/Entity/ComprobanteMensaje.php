<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'com_mensaje')]
#[ORM\Entity]
class ComprobanteMensaje
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $clave = null;

    #[ORM\Column(type: 'text')]
    private ?string $contenido = null;

    #[ORM\ManyToOne(targetEntity: ComprobanteComprobante::class, inversedBy: 'mensajes')]
    #[ORM\JoinColumn(name: 'comprobante_id', referencedColumnName: 'id', nullable: false)]
    private ?ComprobanteComprobante $comprobante = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return sprintf('%s : %s', $this->getClave() ?? '', $this->getContenido() ?? '');
    }

    public function getId(): ?int { return $this->id; }

    public function setClave(?string $clave): self { $this->clave = $clave; return $this; }
    public function getClave(): ?string { return $this->clave; }

    public function setContenido(?string $contenido): self { $this->contenido = $contenido; return $this; }
    public function getContenido(): ?string { return $this->contenido; }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }

    public function setComprobante(?ComprobanteComprobante $comprobante): self { $this->comprobante = $comprobante; return $this; }
    public function getComprobante(): ?ComprobanteComprobante { return $this->comprobante; }
}
