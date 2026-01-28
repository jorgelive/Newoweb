<?php

namespace App\Oweb\Entity;

use App\Oweb\Trait\MainArchivoTrait;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;


/**
 * MaestroMedio
 */
#[ORM\Table(name: 'ser_providermedio')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\TranslationEntity(class: 'App\Oweb\Entity\ServicioProvidermedioTranslation')]
class ServicioProvidermedio
{

    use MainArchivoTrait;

    private string $path = '/carga/servicioprovidermedio';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\OneToMany(targetEntity: ReservaUnitmedioTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'])]
    protected Collection $translations;

    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $titulo = null;

    #[ORM\ManyToOne(targetEntity: ServicioProvider::class, inversedBy: 'providermedios')]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id', nullable: true)]
    protected ?ServicioProvider $provider;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $creado;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $modificado;

    #[Gedmo\Locale]
    private $locale;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations(): ?ArrayCollection
    {
        return $this->translations;
    }

    public function addTranslation(ReservaUnitmedioTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    public function __toString(): string
    {
        if(empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }
        return sprintf('%s', $this->getNombre());
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function setProvider(?ServicioProvider $provider):  self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProvider(): ?ServicioProvider
    {
        return $this->provider;
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
