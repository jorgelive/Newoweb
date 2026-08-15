<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Api\Provider\Pms\PmsUnidadCatalogoProvider;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Escaparate público de una unidad: `.pe/{establecimiento}/{unidad}`.
 *
 * POR QUÉ NO ES LA GUÍA FILTRADA
 * ------------------------------
 * Antes el catálogo reutilizaba el árbol de secciones de `PmsGuia` y solo
 * dejaba pasar los ítems marcados como públicos. El resultado era un escaparate
 * con la ARQUITECTURA DE LA INFORMACIÓN de un manual operativo: la guía agrupa
 * por *cuándo lo necesitas* (llegada, estancia, normas) y al visitante le
 * aparecía «Entrada a la casa · Llegada, llaves y acceso» en mitad de una página
 * comercial. Son dos audiencias con jerarquías distintas sobre el mismo material.
 *
 * Aquí el catálogo tiene su propia estructura —bloques de `PmsCatalogoBloque`,
 * en el orden que vende— y REUTILIZA los `PmsGuiaItem` en vez de duplicarlos:
 * la descripción de la casita se escribe una vez y aparece en los dos sitios.
 *
 * Además admite ítems **huérfanos de sección**: un `PmsGuiaItem` sin ninguna
 * fila en `PmsGuiaSeccionHasItem` no sale en la guía, pero sí aquí. Es lo que
 * permite contenido puramente comercial («Por qué elegirnos», una promoción)
 * sin ensuciar el manual del huésped.
 *
 * QUÉ SE VE Y QUÉ NO
 * ------------------
 * La pertenencia a este catálogo es lo ÚNICO que decide si un ítem sale en el
 * escaparate; `PmsGuiaVisibilidad` pasó a regir solo la guía. Dos ejes
 * ortogonales en vez de un enum haciendo dos trabajos.
 *
 * La red de seguridad no cambia: el provider construye `PmsGuiaAcceso::publico()`,
 * así que un ítem con `{{ door_code }}` metido aquí por error se sirve igual con
 * el mensaje de bloqueo. Pertenecer al catálogo no revela credenciales.
 */
#[ApiResource(
    operations: [
        // Escaparate público por slugs: .pe/{establecimiento}/{unidad}.
        // Se mantiene la MISMA URL que tenía colgando de PmsGuia: es una
        // dirección pública, puede estar compartida e indexada, y cambiarla
        // rompería enlaces vivos. Lo que cambió es qué entidad la sirve.
        new Get(
            uriTemplate: '/public/pax/pms/pms_guia/{establecimiento}/{unidad}',
            uriVariables: [
                'establecimiento' => new Link(fromClass: PmsEstablecimiento::class, identifiers: ['slug']),
                'unidad'          => new Link(fromClass: PmsUnidad::class, identifiers: ['slug']),
            ],
            normalizationContext: ['groups' => ['pax_catalogo:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PmsUnidadCatalogoProvider::class,
        ),
    ],
)]
#[ORM\Entity]
#[ORM\Table(name: 'pms_catalogo')]
#[ORM\HasLifecycleCallbacks]
class PmsCatalogo
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\OneToOne(inversedBy: 'catalogo', targetEntity: PmsUnidad::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'unidad_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: 'El catálogo debe pertenecer a una unidad.')]
    private ?PmsUnidad $unidad = null;

    /**
     * Despublica el escaparate entero sin borrar nada. Con `false`, el provider
     * devuelve 404 igual que si la unidad no tuviera catálogo.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /**
     * Título COMERCIAL, independiente del de la guía. La guía puede llamarse
     * «Manual de Casita 3» y el escaparate «Casita con vista al bosque».
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull]
    private array $titulo = [];

    /**
     * Gancho bajo el título del hero. Opcional.
     *
     * @var list<array{language?: string, content?: string|null}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $subtitulo = [];

    /** @var Collection<int, PmsCatalogoHasItem> */
    #[ORM\OneToMany(mappedBy: 'catalogo', targetEntity: PmsCatalogoHasItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    #[Assert\Valid]
    private Collection $catalogoHasItems;

    // ══════════════════════════════════════════════════════════════════════
    // VISTA PÚBLICA (no persistida). La llena PmsUnidadCatalogoProvider.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Bloques ya armados y ordenados, listos para pintar.
     *
     * @var array<int, array{tipo: string, items: array<int, PmsGuiaItem>}>
     */
    private array $bloquesParaCliente = [];

    /** @var array<int, array{nombre: string, slug: string, imageUrl: string|null, capacidad: int|null}> */
    private array $unidadesHermanasParaCliente = [];

    public function __construct()
    {
        $this->catalogoHasItems = new ArrayCollection();
        $this->id = Uuid::v7();
    }

    #[Groups(['pax_catalogo:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['pax_catalogo:read'])]
    public function getUnidad(): ?PmsUnidad { return $this->unidad; }
    public function setUnidad(?PmsUnidad $unidad): self { $this->unidad = $unidad; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    #[Groups(['pax_catalogo:read'])]
    public function getTitulo(): array { return $this->titulo; }
    /**
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self { $this->titulo = $titulo; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>|null
     */
    #[Groups(['pax_catalogo:read'])]
    public function getSubtitulo(): ?array { return $this->subtitulo; }
    /**
     * @param list<array{language?: string, content?: string|null}>|null $subtitulo
     */
    public function setSubtitulo(?array $subtitulo): self { $this->subtitulo = $subtitulo; return $this; }

    /** @return Collection<int, PmsCatalogoHasItem> */
    public function getCatalogoHasItems(): Collection { return $this->catalogoHasItems; }

    public function addCatalogoHasItem(PmsCatalogoHasItem $rel): self
    {
        if (!$this->catalogoHasItems->contains($rel)) {
            $this->catalogoHasItems->add($rel);
            $rel->setCatalogo($this);
        }
        return $this;
    }

    public function removeCatalogoHasItem(PmsCatalogoHasItem $rel): self
    {
        if ($this->catalogoHasItems->removeElement($rel) && $rel->getCatalogo() === $this) {
            $rel->setCatalogo(null);
        }
        return $this;
    }

    /** @param array<int, array{tipo: string, items: array<int, PmsGuiaItem>}> $bloques */
    public function setBloquesParaCliente(array $bloques): self
    {
        $this->bloquesParaCliente = $bloques;
        return $this;
    }

    /** @return array<int, array{tipo: string, items: array<int, PmsGuiaItem>}> */
    #[Groups(['pax_catalogo:read'])]
    #[SerializedName('bloques')]
    public function getBloquesParaCliente(): array { return $this->bloquesParaCliente; }

    /** @param array<int, array{nombre: string, slug: string, imageUrl: string|null, capacidad: int|null}> $unidades */
    public function setUnidadesHermanasParaCliente(array $unidades): self
    {
        $this->unidadesHermanasParaCliente = $unidades;
        return $this;
    }

    /** @return array<int, array{nombre: string, slug: string, imageUrl: string|null, capacidad: int|null}> */
    #[Groups(['pax_catalogo:read'])]
    #[SerializedName('unidadesHermanas')]
    public function getUnidadesHermanasParaCliente(): array { return $this->unidadesHermanasParaCliente; }

    /**
     * Anclaje para el campo computado del CRUD: EasyAdmin necesita que la
     * propiedad EXISTA aunque su contenido lo pinte un `formatValue`. Mismo
     * recurso que `PmsGuia::getVirtualSecciones()`.
     */
    public function getVirtualBloques(): string { return ''; }

    /**
     * Segundo anclaje, para la insignia de recuento del índice.
     *
     * Devuelve STRING aunque el dato sea un número: `TextField` valida el valor
     * crudo antes de llamar a `formatValue()` y rechaza cualquier cosa que no
     * sea string u objeto con `__toString()`. El recuento real lo pone el
     * `formatValue`, que sí recibe la entidad.
     */
    public function getVirtualContenidos(): string { return ''; }

    /** Cuántos contenidos activos tiene el escaparate. Para el índice del panel. */
    public function getTotalItemsActivos(): int
    {
        $n = 0;
        foreach ($this->catalogoHasItems as $rel) {
            if ($rel->isActivo() && $rel->getItem()) {
                $n++;
            }
        }

        return $n;
    }

    public function __toString(): string
    {
        return sprintf('Catálogo · %s', $this->unidad?->getNombre() ?? 'sin unidad');
    }
}
