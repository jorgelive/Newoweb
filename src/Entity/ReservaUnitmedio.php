<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

use App\Traits\MainArchivoTrait;

/**
 * @ORM\Table(name="res_unitmedio")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @Gedmo\TranslationEntity(class="App\Entity\ReservaUnitmedioTranslation")
 */
class ReservaUnitmedio
{
    use MainArchivoTrait;

    private string $path = '/carga/reservaunitmedio';

    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue(strategy="AUTO") */
    private ?int $id = null;

    /** @ORM\OneToMany(targetEntity="ReservaUnitmedioTranslation", mappedBy="object", cascade={"persist", "remove"}) */
    protected Collection $translations;

    /** @Gedmo\Translatable @ORM\Column(type="string", length=255, nullable=true) */
    private ?string $titulo = null;

    /**
     * NUEVA RELACIÓN: hijo de la característica
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnitcaracteristica", inversedBy="medios")
     * @ORM\JoinColumn(name="unitcaracteristica_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    protected ?ReservaUnitcaracteristica $unitcaracteristica = null;

    /** @Gedmo\Timestampable(on="create") @ORM\Column(type="datetime") */
    private ?\DateTime $creado;

    /** @Gedmo\Timestampable(on="update") @ORM\Column(type="datetime") */
    private ?\DateTime $modificado;

    /** @Gedmo\Locale */
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function __toString(): string
    {
        $c = $this->getUnitcaracteristica();
        return sprintf('Medio #%d%s',
            $this->id ?? 0,
            $c ? ' · tipo: '.$c->getUnittipocaracteristica()->getNombre() : ''
        );
    }

    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }
    public function getTranslations(): ?ArrayCollection { return $this->translations; }
    public function addTranslation(ReservaUnitmedioTranslation $t){ if(!$this->translations->contains($t)){ $this->translations[]=$t; $t->setObject($this);} }

    public function getId(): ?int { return $this->id; }

    public function setTitulo(?string $titulo): self { $this->titulo = $titulo; return $this; }
    public function getTitulo(): ?string { return $this->titulo; }

    public function getUnitcaracteristica(): ?ReservaUnitcaracteristica { return $this->unitcaracteristica; }
    public function setUnitcaracteristica(?ReservaUnitcaracteristica $c): self { $this->unitcaracteristica = $c; return $this; }

    public function setCreado(?\DateTime $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?\DateTime { return $this->creado; }

    public function setModificado(?\DateTime $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?\DateTime { return $this->modificado; }
}
