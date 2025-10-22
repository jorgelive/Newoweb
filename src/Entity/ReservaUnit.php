<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Table(name: 'res_unit')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: 'App\Entity\ReservaUnitTranslation')]
class ReservaUnit
{
    public const DB_VALOR_N1 = 1;
    public const DB_VALOR_N2 = 2;
    public const DB_VALOR_N3 = 3;
    public const DB_VALOR_N4 = 4;
    public const DB_VALOR_N5 = 5;
    public const DB_VALOR_N6 = 6;
    public const DB_VALOR_N7 = 7;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\OneToMany(targetEntity: 'App\Entity\ReservaUnitTranslation', mappedBy: 'object', cascade: ['persist', 'remove'])]
    protected Collection $translations;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $descripcion = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $referencia = null;

    #[ORM\ManyToOne(targetEntity: 'App\Entity\ReservaEstablecimiento', inversedBy: 'units')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false)]
    protected ?ReservaEstablecimiento $establecimiento = null;

    #[ORM\OneToMany(targetEntity: 'App\Entity\ReservaReserva', mappedBy: 'unit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reservas;

    #[ORM\OneToMany(targetEntity: 'App\Entity\ReservaUnitnexo', mappedBy: 'unit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $unitnexos;

    /**
     * Vínculos Unit–Característica (M2M con prioridad en el vínculo)
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\ReservaUnitCaracteristicaLink', mappedBy: 'unit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['prioridad' => 'ASC'])]
    private Collection $unitCaracteristicaLinks;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->reservas = new ArrayCollection();
        $this->unitnexos = new ArrayCollection();
        $this->unitCaracteristicaLinks = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->getNombre(), $this->getEstablecimiento()?->getNombre() ?? '');
    }

    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }

    /** @return Collection|ReservaUnitTranslation[] */
    public function getTranslations(): Collection { return $this->translations; }

    public function addTranslation(ReservaUnitTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
        return $this;
    }

    public function getId(): ?int { return $this->id; }

    public function getResumen(): ?string
    {
        return sprintf('%s %s', $this->getNombre(), $this->getEstablecimiento()?->getNombre() ?? '');
    }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getDescripcion(): ?string { return $this->descripcion; }
    public function setDescripcion(?string $descripcion): self { $this->descripcion = $descripcion; return $this; }

    public function getReferencia(): ?string { return $this->referencia; }
    public function setReferencia(?string $referencia): self { $this->referencia = $referencia; return $this; }

    public function getCreado(): ?DateTimeInterface { return $this->creado; }
    public function setCreado(DateTimeInterface $creado): self { $this->creado = $creado; return $this; }

    public function getModificado(): ?DateTimeInterface { return $this->modificado; }
    public function setModificado(DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }

    public function getEstablecimiento(): ?ReservaEstablecimiento { return $this->establecimiento; }
    public function setEstablecimiento(?ReservaEstablecimiento $establecimiento): self { $this->establecimiento = $establecimiento; return $this; }

    /** @return Collection|ReservaReserva[] */
    public function getReservas(): Collection { return $this->reservas; }

    public function addReserva(ReservaReserva $reserva): self
    {
        if (!$this->reservas->contains($reserva)) {
            $this->reservas->add($reserva);
            $reserva->setUnit($this);
        }
        return $this;
    }

    public function removeReserva(ReservaReserva $reserva): self
    {
        if ($this->reservas->removeElement($reserva)) {
            if ($reserva->getUnit() === $this) {
                $reserva->setUnit(null);
            }
        }
        return $this;
    }

    /** @return Collection|ReservaUnitnexo[] */
    public function getUnitnexos(): Collection { return $this->unitnexos; }

    public function addUnitnexo(ReservaUnitnexo $unitnexo): self
    {
        if (!$this->unitnexos->contains($unitnexo)) {
            $this->unitnexos->add($unitnexo);
            $unitnexo->setUnit($this);
        }
        return $this;
    }

    public function removeUnitnexo(ReservaUnitnexo $unitnexo): self
    {
        if ($this->unitnexos->removeElement($unitnexo)) {
            if ($unitnexo->getUnit() === $this) {
                $unitnexo->setUnit(null);
            }
        }
        return $this;
    }

    /** ================== LINKS (M2M con prioridad en vínculo) ================== */

    /**
     * @return Collection|ReservaUnitCaracteristicaLink[]
     */
    public function getUnitCaracteristicaLinks(): Collection
    {
        return $this->unitCaracteristicaLinks;
    }

    public function addUnitCaracteristicaLink(ReservaUnitCaracteristicaLink $link): self
    {
        if (!$this->unitCaracteristicaLinks->contains($link)) {
            $this->unitCaracteristicaLinks->add($link);
            $link->setUnit($this);
        }
        return $this;
    }

    public function removeUnitCaracteristicaLink(ReservaUnitCaracteristicaLink $link): self
    {
        if ($this->unitCaracteristicaLinks->removeElement($link)) {
            if ($link->getUnit() === $this) {
                $link->setUnit(null);
            }
        }
        return $this;
    }

    public function addCaracteristica(ReservaUnitcaracteristica $caracteristica, ?int $prioridad = null): self
    {
        foreach ($this->unitCaracteristicaLinks as $link) {
            if ($link->getCaracteristica() === $caracteristica) {
                $link->setPrioridad($prioridad);
                return $this;
            }
        }

        $link = (new ReservaUnitCaracteristicaLink())
            ->setUnit($this)
            ->setCaracteristica($caracteristica)
            ->setPrioridad($prioridad);

        $this->unitCaracteristicaLinks->add($link);

        return $this;
    }

    public function removeCaracteristica(ReservaUnitcaracteristica $caracteristica): self
    {
        foreach ($this->unitCaracteristicaLinks as $link) {
            if ($link->getCaracteristica() === $caracteristica) {
                // Mantener sincronizado el lado propietario del vínculo
                return $this->removeUnitCaracteristicaLink($link);
            }
        }
        return $this;
    }

    /**
     * Atajo: devuelve solo las entidades característica (sin el vínculo)
     * @return ReservaUnitcaracteristica[]
     */
    public function getUnitcaracteristicas(): array
    {
        return array_map(
            fn(ReservaUnitCaracteristicaLink $l) => $l->getCaracteristica(),
            $this->unitCaracteristicaLinks->toArray()
        );
    }

    public function hasCaracteristica(ReservaUnitcaracteristica $caracteristica): bool
    {
        foreach ($this->unitCaracteristicaLinks as $link) {
            if ($link->getCaracteristica() === $caracteristica) {
                return true;
            }
        }
        return false;
    }
}
