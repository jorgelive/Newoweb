<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(name: 'mae_tipocontacto')]
#[Gedmo\TranslationEntity(class: MaestroTipocontactoTranslation::class)]
class MaestroTipocontacto
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nombre = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $titulo = null;

    #[Gedmo\Locale]
    private ?string $locale = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $modificado = null;

    #[ORM\OneToMany(mappedBy: 'object', targetEntity: MaestroTipocontactoTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    public function __construct() { $this->translations = new ArrayCollection(); }

    public function getId(): ?int { return $this->id; }
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getTitulo(): ?string { return $this->titulo; }
    public function setTitulo(?string $titulo): self { $this->titulo = $titulo; return $this; }
    public function setTranslatableLocale(?string $locale): void { $this->locale = $locale; }
    public function getCreado(): ?\DateTimeInterface { return $this->creado; }
    public function getModificado(): ?\DateTimeInterface { return $this->modificado; }
    public function getTranslations(): Collection { return $this->translations; }
    public function addTranslation(MaestroTipocontactoTranslation $t): self {
        if (!$this->translations->contains($t)) {
            $this->translations->add($t);
            $t->setObject($this);
        }
        return $this;
    }
    public function removeTranslation(MaestroTipocontactoTranslation $t): self {
        $this->translations->removeElement($t);
        return $this;
    }
}
