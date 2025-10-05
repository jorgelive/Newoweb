<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * ReservaEstado
 *
 * Importante:
 * - Las constantes DB_VALOR_* son contrato externo (no cambiar valores).
 * - orphanRemoval=true: elimina hijos huérfanos al desvincular (ojo en cargas masivas).
 */
#[ORM\Table(name: 'res_estado')]
#[ORM\Entity]
class ReservaEstado
{
    public const DB_VALOR_ABIERTO = 1;
    public const DB_VALOR_CONFIRMADO = 2;
    public const DB_VALOR_CANCELADO = 3;
    public const DB_VALOR_PAGO_PARCIAL = 4;
    public const DB_VALOR_PAGO_TOTAL = 5;
    public const DB_VALOR_PARA_CANCELACION = 6;
    public const DB_VALOR_INICIAL = 7;

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $color = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $colorcalendar = null;

    #[ORM\Column(name: 'habilitar_resumen_publico', type: 'boolean', options: ['default' => false])]
    private bool $habilitarResumenPublico = false;

    #[ORM\OneToMany(targetEntity: 'ReservaReserva', mappedBy: 'estado', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reservas;

    // ✅ Mapeadas como columnas para que Gedmo pueda escribir
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    public function __construct() {
        $this->reservas = new ArrayCollection();
    }

    public function __toString() { return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? ''; }

    public function getId(): ?int { return $this->id; }
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(string $color): self { $this->color = $color; return $this; }

    public function getColorcalendar(): ?string { return $this->colorcalendar; }
    public function setColorcalendar(string $colorcalendar): self { $this->colorcalendar = $colorcalendar; return $this; }

    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function setCreado(\DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function setModificado(\DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }

    /** @return Collection<int, ReservaReserva> */
    public function getReservas(): Collection { return $this->reservas; }

    public function addReserva(ReservaReserva $reserva): self
    {
        if(!$this->reservas->contains($reserva)) {
            $this->reservas[] = $reserva;
            $reserva->setEstado($this);
        }
        return $this;
    }

    public function removeReserva(ReservaReserva $reserva): self
    {
        if($this->reservas->removeElement($reserva)) {
            if($reserva->getEstado() === $this) {
                $reserva->setEstado(null);
            }
        }
        return $this;
    }

    public function isHabilitarResumenPublico(): bool { return (bool)$this->habilitarResumenPublico; }
    public function getHabilitarResumenPublico(): bool { return (bool)$this->habilitarResumenPublico; }
    public function setHabilitarResumenPublico(bool $v): self { $this->habilitarResumenPublico = $v; return $this; }
}
