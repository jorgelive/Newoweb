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
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_seccion')]
#[ORM\HasLifecycleCallbacks]
class PmsGuiaSeccion
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'El nombre interno es obligatorio para la gestión administrativa')]
    private ?string $nombreInterno = null;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'Debe ingresar al menos el título en español')]
    #[Assert\Count(min: 1, minMessage: 'Debe ingresar al menos un título')]
    private array $titulo = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'El icono no puede exceder los 50 caracteres')]
    private ?string $icono = 'fa-info-circle';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Assert\NotNull]
    private bool $esComun = false;

    // Relación INVERSA hacia la tabla intermedia (Seccion <-> Item)
    #[ORM\OneToMany(
        mappedBy: 'seccion',
        targetEntity: PmsGuiaSeccionHasItem::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    #[Assert\Valid]
    private Collection $seccionHasItems;

    public function __construct()
    {
        $this->seccionHasItems = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->titulo = [];
        $this->icono = 'fa-info-circle';
        $this->esComun = false;
        $this->nombreInterno = '';
    }

    // =========================================================================
    // MÉTODOS PARA API (GETTERS CON GROUPS)
    // =========================================================================

    /**
     * 🔴 IMPORTANTE: Sobrescribimos getId del Trait para añadirle el Grupo.
     * Si no hacemos esto, la API no devuelve el ID de la sección.
     */
    #[Groups(['pax_evento:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * 🔥 "Virtual Property": Devuelve los items limpios y ordenados,
     * ocultando la complejidad de la tabla intermedia 'seccionHasItems'.
     */
    #[Groups(['pax_evento:read'])]
    #[SerializedName('items')]
    public function getItemsApi(): array
    {
        // 1. Filtramos solo los items activos en esta sección
        $relaciones = $this->seccionHasItems->filter(
            fn(PmsGuiaSeccionHasItem $rel) => $rel->isActivo()
        );

        // 2. Extraemos el objeto Item real de cada relación
        // (El orden ya viene dado por la anotación ORM\OrderBy en la propiedad)
        $items = [];
        foreach ($relaciones as $rel) {
            if ($rel->getItem()) {
                $items[] = $rel->getItem();
            }
        }
        return $items;
    }

    #[Groups(['pax_evento:read'])]
    public function getTitulo(): array
    {
        return MaestroIdioma::ordenarParaFormulario($this->titulo);
    }

    #[Groups(['pax_evento:read'])]
    public function getIcono(): ?string
    {
        return $this->icono;
    }

    // =========================================================================
    // GETTERS Y SETTERS ADMINISTRATIVOS
    // =========================================================================

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(string $nombreInterno): self
    {
        $this->nombreInterno = $nombreInterno;
        return $this;
    }

    public function setTitulo(array $titulo): self
    {
        $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this;
    }

    public function setIcono(?string $icono): self
    {
        $this->icono = $icono ?: 'fa-info-circle';
        return $this;
    }

    public function isEsComun(): bool
    {
        return $this->esComun;
    }

    public function setEsComun(bool $esComun): self
    {
        $this->esComun = $esComun;
        return $this;
    }

    // =========================================================================
    // GESTIÓN DE RELACIONES (TABLA INTERMEDIA)
    // =========================================================================

    public function getSeccionHasItems(): Collection
    {
        return $this->seccionHasItems;
    }

    public function addSeccionHasItem(PmsGuiaSeccionHasItem $item): self
    {
        if (!$this->seccionHasItems->contains($item)) {
            $this->seccionHasItems->add($item);
            $item->setSeccion($this);
        }
        return $this;
    }

    public function removeSeccionHasItem(PmsGuiaSeccionHasItem $item): self
    {
        if ($this->seccionHasItems->removeElement($item)) {
            if ($item->getSeccion() === $this) {
                $item->setSeccion(null);
            }
        }
        return $this;
    }

    // =========================================================================
    // LÓGICA Y VALIDACIONES
    // =========================================================================

    public function __toString(): string
    {
        // Prioridad al nombre interno para selectores
        return $this->nombreInterno ?: ($this->titulo['es'] ?? (string) $this->id);
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->seccionHasItems->isEmpty()) {
            $context->buildViolation('La sección debe contener al menos un ítem.')
                ->atPath('seccionHasItems')
                ->addViolation();
        }

        $espanolEncontrado = false;

        // Verificamos que no esté vacío el campo principal
        if (!empty($this->titulo) && is_iterable($this->titulo)) {

            foreach ($this->titulo as $item) {
                // 1. Accedemos como Array Asociativo: $item['language']
                // Usamos operador null coalescing (??) por seguridad
                $lang = $item['language'] ?? null;
                $content = $item['content'] ?? null;

                // 2. Validamos si es español y tiene contenido real
                if ($lang === 'es' && !empty(trim($content))) {
                    $espanolEncontrado = true;
                    break;
                }
            }
        }

        if (!$espanolEncontrado) {
            $context->buildViolation('El título en español (es) es obligatorio.')
                ->atPath('titulo')
                ->addViolation();
        }
    }
}