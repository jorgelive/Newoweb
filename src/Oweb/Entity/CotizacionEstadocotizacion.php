<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionEstadocotizacion
 */
#[ORM\Table(name: 'cot_estadocotizacion')]
#[ORM\Entity]
class CotizacionEstadocotizacion
{
    public const DB_VALOR_PENDIENTE   = 1;
    public const DB_VALOR_ARCHIVADO   = 2;
    public const DB_VALOR_CONFIRMADO  = 3;
    public const DB_VALOR_OPERADO     = 4;
    public const DB_VALOR_CANCELADO   = 5;
    public const DB_VALOR_PLANTILLA   = 6;
    public const DB_VALOR_WAITING     = 7;

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    // Consigna: strings inicializados a null aunque no sean nulables en DB
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private ?bool $nopublico = null;

    // Fechas con DateTimeInterface y null para compatibilidad con Gedmo Timestampable
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNopublico(?bool $nopublico): self
    {
        $this->nopublico = $nopublico;
        return $this;
    }

    public function isNopublico(): ?bool
    {
        return $this->nopublico;
    }

    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }
}
