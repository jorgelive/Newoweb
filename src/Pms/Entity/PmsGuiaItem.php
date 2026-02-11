<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\AutoTranslateControlTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item')]
#[ORM\HasLifecycleCallbacks]
class PmsGuiaItem
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    // Tipos actualizados según tu controlador
    public const TIPO_TARJETA = 'card';
    public const TIPO_ALBUM = 'album';
    public const TIPO_AVISO = 'alert';

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaSeccionHasItem::class, cascade: ['persist', 'remove'])]
    private Collection $itemHasSecciones;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'El nombre interno es obligatorio')]
    private ?string $nombreInterno = null;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::TIPO_TARJETA, self::TIPO_ALBUM, self::TIPO_AVISO])]
    #[Groups(['pax_evento:read'])]
    private ?string $tipo = self::TIPO_TARJETA;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'Debe ingresar al menos el título en español')]
    #[Assert\Count(min: 1, minMessage: 'Debe ingresar al menos un título')]
    private array $titulo = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    private ?array $descripcion = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $labelBoton = [];

    // Aquí se guardará la URL del botón y cualquier configuración futura
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = [];

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaItemGaleria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    #[Assert\Valid]
    #[Groups(['pax_evento:read'])]
    private Collection $galeria;

    public function __construct()
    {
        $this->galeria = new ArrayCollection();
        $this->itemHasSecciones = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->titulo = [];
        $this->descripcion = [];
        $this->labelBoton = [];
        $this->tipo = self::TIPO_TARJETA;
        $this->nombreInterno = '';
        $this->metadata = [];
    }

    // =========================================================================
    // 🔗 PROPIEDAD VIRTUAL: URL BOTÓN
    // =========================================================================

    /**
     * Esta propiedad NO existe en la BD, se lee del JSON metadata.
     * Al tener el grupo 'pax_evento:read', la API la enviará limpia al Vue.
     */
    #[Groups(['pax_evento:read'])]
    public function getUrlBoton(): ?string
    {
        return $this->metadata['urlBoton'] ?? null;
    }

    public function setUrlBoton(?string $val): self
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }

        // Si viene vacío, lo limpiamos para no ensuciar el JSON
        if (empty($val)) {
            unset($this->metadata['urlBoton']);
        } else {
            $this->metadata['urlBoton'] = $val;
        }

        return $this;
    }

    // =========================================================================
    // 📥 GETTERS Y SETTERS
    // =========================================================================

    public function getNombreInterno(): ?string { return $this->nombreInterno; }
    public function setNombreInterno(string $nombreInterno): self { $this->nombreInterno = $nombreInterno; return $this; }

    public function getTipo(): string { return $this->tipo ?? self::TIPO_TARJETA; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    #[Groups(['pax_evento:read'])]
    public function getTitulo(): array { return MaestroIdioma::ordenarParaFormulario($this->titulo); }
    public function setTitulo(array $titulo): self { $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this; }

    #[Groups(['pax_evento:read'])]
    public function getDescripcion(): ?array { return MaestroIdioma::ordenarParaFormulario($this->descripcion ?? []); }
    public function setDescripcion(?array $descripcion): self { $this->descripcion = MaestroIdioma::normalizarParaDB($descripcion ?? []); return $this; }

    #[Groups(['pax_evento:read'])]
    public function getLabelBoton(): ?array { return MaestroIdioma::ordenarParaFormulario($this->labelBoton ?? []); }
    public function setLabelBoton(?array $labelBoton): self { $this->labelBoton = MaestroIdioma::normalizarParaDB($labelBoton ?? []); return $this; }

    public function getMetadata(): array { return $this->metadata ?? []; }
    public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }

    public function getGaleria(): Collection { return $this->galeria; }
    public function addGaleria(PmsGuiaItemGaleria $galeria): self { if (!$this->galeria->contains($galeria)) { $this->galeria->add($galeria); $galeria->setItem($this); } return $this; }
    public function removeGaleria(PmsGuiaItemGaleria $galeria): self { if ($this->galeria->removeElement($galeria)) { if ($galeria->getItem() === $this) { $galeria->setItem(null); } } return $this; }

    public function getItemHasSecciones(): Collection { return $this->itemHasSecciones; }

    public function __toString(): string { return $this->nombreInterno ?: ($this->titulo['es'] ?? 'Ítem sin nombre'); }

    // =========================================================================
    // ✅ VALIDACIONES
    // =========================================================================

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // 1. Título Español Obligatorio
        $espanolEncontrado = false;
        if (!empty($this->titulo)) {
            foreach ($this->titulo as $item) {
                if (($item['language'] ?? '') === 'es' && !empty(trim($item['content'] ?? ''))) {
                    $espanolEncontrado = true;
                    break;
                }
            }
        }
        if (!$espanolEncontrado) {
            $context->buildViolation('El título en español es obligatorio.')->atPath('titulo')->addViolation();
        }

        // 2. Coherencia del Botón: Si hay URL, debe haber Texto
        $hasUrl = !empty($this->getUrlBoton());
        $hasLabel = false;
        if (!empty($this->labelBoton)) {
            foreach ($this->labelBoton as $item) {
                if (!empty(trim($item['content'] ?? ''))) {
                    $hasLabel = true;
                    break;
                }
            }
        }

        if ($hasUrl && !$hasLabel) {
            $context->buildViolation('Si pones una URL, el botón debe tener texto.')
                ->atPath('labelBoton')
                ->addViolation();
        }
    }
}