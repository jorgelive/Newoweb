<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

/**
 * ServicioTipotarifa
 */
#[ORM\Table(name: 'ser_tipotarifa')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: 'App\Oweb\Entity\ServicioTipotarifaTranslation')]
class ServicioTipotarifa implements Translatable
{
    public const DB_VALOR_NORMAL = 1;
    public const DB_VALOR_OPCIONAL = 2;
    public const DB_VALOR_CTA_PAX = 3;
    public const DB_VALOR_CTA_PAX_ASISTENCIA = 4;
    public const DB_VALOR_NO_NECESARIO = 5;
    public const DB_VALOR_CORTESIA = 6;

    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    /**
     * Campo traducible
     */
    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $titulo = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 1])]
    private bool $comisionable = true;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $ocultoenresumen = false;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $mostrarcostoincluye = false;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $listacolor = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $listaclase = null;

    /**
     * Marcar como nullable para evitar warnings hasta la primera persistencia
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    /**
     * Marcar como nullable para evitar warnings hasta la primera actualización
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    /**
     * Relación inversa a las traducciones (Gedmo PersonalTranslation)
     *
     * @var Collection<int,ServicioTipotarifaTranslation>
     */
    #[ORM\OneToMany(targetEntity: ServicioTipotarifaTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    // ----------------------
    // Translatable helpers
    // ----------------------

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /** @return Collection<int,ServicioTipotarifaTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ServicioTipotarifaTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
        return $this;
    }

    public function removeTranslation(ServicioTipotarifaTranslation $translation): self
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getObject() === $this) {
                $translation->setObject(null);
            }
        }
        return $this;
    }

    // ----------------------
    // Magic & basics
    // ----------------------

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId()) ?? '';
    }

    // ----------------------
    // Getters / Setters
    // ----------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setListacolor(?string $listacolor): self
    {
        $this->listacolor = $listacolor;
        return $this;
    }

    public function getListacolor(): ?string
    {
        return $this->listacolor;
    }

    public function setListaclase(?string $listaclase): self
    {
        $this->listaclase = $listaclase;
        return $this;
    }

    public function getListaclase(): ?string
    {
        return $this->listaclase;
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

    public function setTitulo(?string $titulo = null): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setComisionable(bool $comisionable): self
    {
        $this->comisionable = $comisionable;
        return $this;
    }

    public function isComisionable(): bool
    {
        return $this->comisionable;
    }

    public function setOcultoenresumen(bool $ocultoenresumen): self
    {
        $this->ocultoenresumen = $ocultoenresumen;
        return $this;
    }

    public function isOcultoenresumen(): bool
    {
        return $this->ocultoenresumen;
    }

    public function setMostrarcostoincluye(bool $mostrarcostoincluye): self
    {
        $this->mostrarcostoincluye = $mostrarcostoincluye;
        return $this;
    }

    public function isMostrarcostoincluye(): bool
    {
        return $this->mostrarcostoincluye;
    }
}
