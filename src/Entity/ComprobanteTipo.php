<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'com_tipo')]
#[ORM\Entity]
class ComprobanteTipo
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 3)]
    private ?string $codigo = null;

    #[ORM\Column(type: 'string', length: 3)]
    private ?string $codigoexterno = null;

    #[ORM\Column(type: 'string', length: 5)]
    private ?string $serie = null;

    #[ORM\Column(type: 'integer')]
    private int $correlativo = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $esnotacredito = false;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    public function getId(): ?int { return $this->id; }

    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getNombre(): ?string { return $this->nombre; }

    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }
    public function getCodigo(): ?string { return $this->codigo; }

    public function setCreado(?\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?\DateTimeInterface { return $this->creado; }

    public function setModificado(?\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }

    public function setSerie(?string $serie): self { $this->serie = $serie; return $this; }
    public function getSerie(): ?string { return $this->serie; }

    public function setCorrelativo(int $correlativo): self { $this->correlativo = $correlativo; return $this; }
    public function getCorrelativo(): int { return $this->correlativo; }

    public function setCodigoexterno(?string $codigoexterno): self { $this->codigoexterno = $codigoexterno; return $this; }
    public function getCodigoexterno(): ?string { return $this->codigoexterno; }

    public function setEsnotacredito(bool $esnotacredito): self { $this->esnotacredito = $esnotacredito; return $this; }
    public function isEsnotacredito(): bool { return $this->esnotacredito; }
}
