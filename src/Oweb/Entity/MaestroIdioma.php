<?php

declare(strict_types=1);

namespace App\Oweb\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad Maestra de Idiomas.
 * Define qué idiomas están disponibles y cuáles disparan traducción automática.
 */
#[ORM\Table(name: 'mae_idioma')]
#[ORM\Entity]
class MaestroIdioma
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $codigo = null;

    /**
     * Define si este idioma se traduce automáticamente mediante Google Translate API.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $prioritario = false;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $modificado = null;

    public function __toString(): string
    {
        return $this->getNombre() ?? (string) $this->getId();
    }

    // ----- Getters y Setters Explícitos [cite: 2026-01-14] -----

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(string $codigo): self
    {
        $this->codigo = $codigo;
        return $this;
    }

    public function isPrioritario(): bool
    {
        return $this->prioritario;
    }

    public function setPrioritario(bool $prioritario): self
    {
        $this->prioritario = $prioritario;
        return $this;
    }

    public function getCreado(): ?\DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(\DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getModificado(): ?\DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(\DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }
}