<?php

namespace App\Entity;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

use App\Traits\MainArchivoTrait;


/**
 * @ORM\HasLifecycleCallbacks
 */
#[ORM\Table(name: 'mae_medio')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\TranslationEntity(class: 'App\Entity\MaestroMedioTranslation')]
class MaestroMedio
{
    use MainArchivoTrait;

    private $path = '/carga/maestromedio';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\OneToMany(targetEntity: 'MaestroMedioTranslation', mappedBy: 'object', cascade: ['persist', 'remove'])]
    protected Collection $translations;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $titulo = null;

    #[ORM\ManyToOne(targetEntity: 'MaestroClasemedio', inversedBy: 'medios')]
    #[ORM\JoinColumn(name: 'clasemedio_id', referencedColumnName: 'id', nullable: true)]
    protected ?MaestroClasemedio $clasemedio;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $creado;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $modificado;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations()
    {
        return $this->translations;
    }

    public function addTranslation(MaestroMedioTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    public function __toString(): string
    {
        if(empty($this->getClasemedio()) || empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }
        return sprintf('%s: %s', $this->getClasemedio()->getNombre(), $this->getNombre());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setClasemedio(?MaestroClasemedio $clasemedio): self
    {
        $this->clasemedio = $clasemedio;

        return $this;
    }

    public function getClasemedio(): ?MaestroClasemedio
    {
        return $this->clasemedio;
    }

    public function setCreado(?DateTime $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getCreado(): ?DateTime
    {
        return $this->creado;
    }

    public function setModificado(?DateTime $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getModificado(): ?DateTime
    {
        return $this->modificado;
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
}
