<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * CotizacionEstadocotcomponente
 */
#[ORM\Table(name: 'cot_estadocotcomponente')]
#[ORM\Entity]
class CotizacionEstadocotcomponente
{
    public const DB_VALOR_PENDIENTE = 1;
    public const DB_VALOR_CONFIRMADO = 2;
    public const DB_VALOR_RECONFIRMADO = 3;

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    // Consigna: inicializar strings a null por compatibilidad con instanciación en Symfony
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $color = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $colorcalendar = null;

    // Fechas tipadas a DateTimeInterface, permitiendo null (Gedmo repuebla en persist/update)
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

    public function setColor(?string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColorcalendar(?string $colorcalendar): self
    {
        $this->colorcalendar = $colorcalendar;
        return $this;
    }

    public function getColorcalendar(): ?string
    {
        return $this->colorcalendar;
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
