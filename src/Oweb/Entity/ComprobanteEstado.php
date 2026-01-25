<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'com_estado')]
#[ORM\Entity]
class ComprobanteEstado
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 3)]
    private ?string $codigo = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    public function getId(): ?int { return $this->id; }

    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getNombre(): ?string { return $this->nombre; }

    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }
    public function getCodigo(): ?string { return $this->codigo; }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }
}
