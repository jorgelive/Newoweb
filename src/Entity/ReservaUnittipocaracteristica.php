<?php


namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnittipocaracteristica
 *
 * @ORM\Table(name="res_unittipocaracteristica")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnittipocaracteristicaTranslation")
 */
class ReservaUnittipocaracteristica
{

    public const DB_VALOR_DESCRIPCION = 1;
	public const DB_VALOR_LIMPIEZA = 2;
	public const DB_VALOR_GALERIA = 3;
	public const DB_VALOR_INVENTARIO = 4;
	public const DB_VALOR_CALEFACTOR = 5;
	public const DB_VALOR_RECOMENDACIONES = 6;
	public const DB_VALOR_LLAVES = 7;
	public const DB_VALOR_DUCHAS = 8;
	public const DB_VALOR_WIFI = 9;
	public const DB_VALOR_PAGO = 11;

    /**
     * @var int
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var ArrayCollection
     * @ORM\OneToMany(targetEntity="ReservaUnittipocaracteristicaTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @var string
     * @ORM\Column(type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=100, nullable=false)
     */
    private $titulo;

    /**
     * @var string|null
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private $iconcolor;

    /**
     * @var string|null
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private $iconclase;

    /**
     * Controla si este TIPO de característica es visible en el resumen público.
     *
     * @ORM\Column(name="visible_en_resumen_publico", type="boolean", options={"default": false})
     */
    private bool $visibleEnResumenPublico = false;

    /**
     * @var ArrayCollection
     * @ORM\OneToMany(targetEntity="ReservaUnitcaracteristica", mappedBy="unittipocaracteristica", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $unitcaracteristicas;

    /**
     * @var \DateTime $creado
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $modificado;

    /**
     * @Gedmo\Locale
     */
    private $locale;

    public function __construct()
    {
        $this->unitcaracteristicas = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

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

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }
    public function setTitulo(string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getIconcolor(): ?string
    {
        return $this->iconcolor;
    }
    public function setIconcolor(?string $iconcolor): self
    {
        $this->iconcolor = $iconcolor;
        return $this;
    }

    public function getIconclase(): ?string
    {
        return $this->iconclase;
    }
    public function setIconclase(?string $iconclase): self
    {
        $this->iconclase = $iconclase;
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

    /**
     * @return Collection<int, ReservaUnitcaracteristica>
     */
    public function getUnitcaracteristicas(): Collection
    {
        return $this->unitcaracteristicas;
    }

    public function addUnitcaracteristica(ReservaUnitcaracteristica $unitcaracteristica): self
    {
        if (!$this->unitcaracteristicas->contains($unitcaracteristica)) {
            $this->unitcaracteristicas[] = $unitcaracteristica;
            $unitcaracteristica->setUnittipocaracteristica($this);
        }
        return $this;
    }

    public function removeUnitcaracteristica(ReservaUnitcaracteristica $unitcaracteristica): self
    {
        if ($this->unitcaracteristicas->removeElement($unitcaracteristica)) {
            if ($unitcaracteristica->getUnittipocaracteristica() === $this) {
                $unitcaracteristica->setUnittipocaracteristica(null);
            }
        }
        return $this;
    }

    public function isVisibleEnResumenPublico(): bool
    {
        return (bool)$this->visibleEnResumenPublico;
    }

    public function setVisibleEnResumenPublico(bool $v): self
    {
        $this->visibleEnResumenPublico = $v;
        return $this;
    }
}
