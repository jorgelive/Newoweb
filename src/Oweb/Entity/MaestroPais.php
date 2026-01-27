<?php

declare(strict_types=1);

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad Maestra de Países.
 * Centraliza códigos de integración y lógica de procedencia.
 */
#[ORM\Table(name: 'mae_pais')]
#[ORM\Entity]
class MaestroPais
{
    /** Estándares internacionales y códigos de integración [cite: 2026-01-14] */
    public const ISO_PERU = 'PE';
    public const CODIGO_CIUDAD_DEFAULT_COSETTUR_PERU = '1610';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 2, nullable: true)]
    private ?string $iso2 = null;

    /** Código para Ministerio de Cultura */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigomc = null;

    /** Código para PeruRail */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigopr = null;

    /** Código para Consettur */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigocon = null;

    /** Prioridad en selectores */
    #[ORM\Column(name: 'prioritario', type: 'boolean', options: ['default' => false])]
    private bool $prioritario = false;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    /**
     * Representación en string del país.
     */
    public function __toString(): string
    {
        return $this->getNombre() ?? (string) $this->getId();
    }

    // ----- Lógica de Negocio (Basada en ISO) [cite: 2026-01-14] -----

    /**
     * Determina la categoría según el Ministerio de Cultura.
     */
    public function getProcedenciaMcNombre(): string
    {
        if ($this->iso2 === self::ISO_PERU) {
            return 'Peruano';
        }

        if (in_array($this->iso2, ['BO', 'EC', 'CO'])) {
            return 'Países CAN y Residente extranjero';
        }

        return 'Extranjero';
    }

    /**
     * Devuelve el código de procedencia para MC.
     */
    public function getProcedenciaMcCodigo(): int
    {
        return ($this->iso2 === self::ISO_PERU) ? 2 : 1;
    }

    // ----- Getters y Setters Explícitos [cite: 2026-01-14] -----

    public function getId(): ?int { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self {
        $this->nombre = $nombre;
        return $this;
    }

    public function getIso2(): ?string { return $this->iso2; }
    public function setIso2(?string $iso2): self {
        $this->iso2 = $iso2 !== null ? strtoupper($iso2) : null;
        return $this;
    }

    public function isPrioritario(): bool { return $this->prioritario; }
    public function setPrioritario(bool $prioritario): self {
        $this->prioritario = $prioritario;
        return $this;
    }

    public function getCodigomc(): ?int { return $this->codigomc; }
    public function setCodigomc(?int $codigomc): self {
        $this->codigomc = $codigomc;
        return $this;
    }

    public function getCodigopr(): ?int { return $this->codigopr; }
    public function setCodigopr(?int $codigopr): self {
        $this->codigopr = $codigopr;
        return $this;
    }

    public function getCodigocon(): ?int { return $this->codigocon; }
    public function setCodigocon(?int $codigocon): self {
        $this->codigocon = $codigocon;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface { return $this->creado; }
    public function setCreado(?DateTimeInterface $creado): self {
        $this->creado = $creado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface { return $this->modificado; }
    public function setModificado(?DateTimeInterface $modificado): self {
        $this->modificado = $modificado;
        return $this;
    }
}