<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\AutoTranslateControlTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia')]
#[ORM\HasLifecycleCallbacks]
/**
 * PmsGuia centraliza la información de la guía para el huésped.
 * La operación principal se accede vía el UUID de la unidad vinculada.
 */

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/public/pax/pms/pms_guia/pms_unidad/{unidad}',
            uriVariables: [
                'unidad' => new Link(
                    toProperty: 'unidad',
                    fromClass: PmsUnidad::class,      // sigue siendo el id
                    identifiers: ['id']      // propiedad en PmsGuia (OneToOne/ManyToOne)
                ),
            ],
            normalizationContext: ['groups' => ['pax_evento:read']],
        )
    ],
    order: ['createdAt' => 'DESC']
)]
class PmsGuia
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\OneToOne(inversedBy: 'guia', targetEntity: PmsUnidad::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'unidad_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsUnidad $unidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull]
    private array $titulo = [];

    #[ORM\OneToMany(mappedBy: 'guia', targetEntity: PmsGuiaHasSeccion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $guiaHasSecciones;

    public function __construct()
    {
        $this->guiaHasSecciones = new ArrayCollection();
        $this->titulo = [];
        $this->id = Uuid::v7();
    }

    // --- MÉTODOS PARA API (Grupos en Getters) ---

    #[Groups(['pax_evento:read'])]
    #[SerializedName('secciones')]
    public function getSeccionesApi(): array
    {
        $relaciones = $this->guiaHasSecciones->filter(fn(PmsGuiaHasSeccion $rel) => $rel->isActivo());

        $secciones = [];
        foreach ($relaciones as $rel) {
            if ($rel->getSeccion()) {
                $secciones[] = $rel->getSeccion();
            }
        }
        return $secciones;
    }

    #[Groups(['pax_evento:read'])]
    public function getUnidad(): ?PmsUnidad { return $this->unidad; }

    #[Groups(['pax_evento:read'])]
    public function isActivo(): bool { return $this->activo; }

    #[Groups(['pax_evento:read'])]
    public function getTitulo(): array
    {
        return MaestroIdioma::ordenarParaFormulario($this->titulo);
    }

    // --- SETTERS Y LÓGICA INTERNA ---

    public function setUnidad(?PmsUnidad $unidad): self { $this->unidad = $unidad; return $this; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    public function setTitulo(array $titulo): self
    {
        $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this;
    }

    public function getGuiaHasSecciones(): Collection { return $this->guiaHasSecciones; }
    public function addGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self { if (!$this->guiaHasSecciones->contains($guiaHasSeccion)) { $this->guiaHasSecciones->add($guiaHasSeccion); $guiaHasSeccion->setGuia($this); } return $this; }
    public function removeGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self { if ($this->guiaHasSecciones->removeElement($guiaHasSeccion)) { if ($guiaHasSeccion->getGuia() === $this) { $guiaHasSeccion->setGuia(null); } } return $this; }

    public function __toString(): string
    {
        $nombreUnidad = $this->unidad?->getNombre();
        $tituloGuia = $this->titulo['es'] ?? null;
        return $tituloGuia ? "$tituloGuia ($nombreUnidad)" : ($nombreUnidad ?? 'Guía UUID ' . $this->getId());
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
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