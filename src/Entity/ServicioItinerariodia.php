<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;


/**
 * ServicioItinerariodia
 *
 * @ORM\Table(name="ser_itinerariodia")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioItinerariodiaTranslation")
 */
class ServicioItinerariodia
{

    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioItinerariodiaTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected Collection $translations;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioItinerario", inversedBy="itinerariodias")
     * @ORM\JoinColumn(name="itinerario_id", referencedColumnName="id", nullable=false)
     */
    protected ?ServicioItinerario $itinerario;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $dia = 1;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=100)
     */
    private ?string $titulo = null;

    /**
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private ?bool $importante = false;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $contenido = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $creado;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $modificado;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioItidiaarchivo", mappedBy="itinerariodia", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private Collection $itidiaarchivos;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioNotaitinerariodia", inversedBy="itinerariodias")
     * @ORM\JoinColumn(name="notaitinerariodia_id", referencedColumnName="id", nullable=true)
     */
    protected ?ServicioNotaitinerariodia $notaitinerariodia;

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->itidiaarchivos = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newItidiaarchivos = new ArrayCollection();
            foreach($this->itidiaarchivos as $itidiaarchivo) {
                $newItidiaarchivo = clone $itidiaarchivo;
                $newItidiaarchivo->setItinerariodia($this);
                $newItidiaarchivos->add($newItidiaarchivo);
            }

            $this->itidiaarchivos = $newItidiaarchivos;
        }
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ServicioItinerariodiaTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    public function __toString(): string
    {
        return $this->getTitulo() ?? sprintf('Dia %d: %s', $this->getDia(), $this->getItinerario()->getNombre());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setDia(?int $dia): self
    {
        $this->dia = $dia;
    
        return $this;
    }

    public function getDia(): ?int
    {
        return $this->dia;
    }

    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setContenido(?string $contenido): self
    {
        $this->contenido = $contenido;
    
        return $this;
    }

    public function getContenido(): ?string
    {
        return $this->contenido;
    }

    public function setCreado(?\DateTime $creado): self
    {
        $this->creado = $creado;
    
        return $this;
    }

    public function getCreado(): \DateTime
    {
        return $this->creado;
    }

    public function setModificado(?\DateTime $modificado): self
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    public function getModificado(): \DateTime
    {
        return $this->modificado;
    }

    public function setItinerario(?ServicioItinerario $itinerario): self
    {
        $this->itinerario = $itinerario;
    
        return $this;
    }

    public function getItinerario(): ?ServicioItinerario
    {
        return $this->itinerario;
    }


    public function addItidiaarchivo(ServicioItidiaarchivo $itidiaarchivo): self
    {
        $itidiaarchivo->setItinerariodia($this);

        $this->itidiaarchivos[] = $itidiaarchivo;
    
        return $this;
    }

    public function removeItidiaarchivo(ServicioItidiaarchivo $itidiaarchivo): bool
    {
        return $this->itidiaarchivos->removeElement($itidiaarchivo);
    }

    public function getItidiaarchivos(): Collection
    {
        return $this->itidiaarchivos;
    }

    public function setNotaitinerariodia(ServicioNotaitinerariodia $notaitinerariodia): self
    {
        $this->notaitinerariodia = $notaitinerariodia;

        return $this;
    }

    public function getNotaitinerariodia(): ?ServicioNotaitinerariodia
    {
        return $this->notaitinerariodia;
    }

    public function setImportante(?bool $importante): self
    {
        $this->importante = $importante;
    
        return $this;
    }

    public function isImportante(): ?bool
    {
        return $this->importante;
    }
}
