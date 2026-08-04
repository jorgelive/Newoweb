<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Pms\Enum\PmsCatalogoBloque;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un `PmsGuiaItem` colocado dentro de un `PmsCatalogo`: el "ManyToMany con
 * vitaminas" entre la unidad y sus contenidos comerciales.
 *
 * POR QUÉ EL BLOQUE VIVE AQUÍ Y NO EN EL ÍTEM
 * -------------------------------------------
 * Porque el bloque no describe al ítem, describe **el papel que juega en el
 * escaparate**. El mismo contenido —la dirección de la casa— es «Entrada a la
 * casa» en la guía y «Ubicación» aquí. Metiendo el bloque en `PmsGuiaItem`
 * habría que elegir una de las dos lecturas y falsear la otra.
 *
 * Es el mismo patrón que `PmsGuiaSeccionHasItem` (join con `orden` y `activo`),
 * que ya resolvía este problema para la guía.
 *
 * ÍTEMS HUÉRFANOS DE SECCIÓN
 * --------------------------
 * Un `PmsGuiaItem` no necesita pertenecer a ninguna sección: la relación vive en
 * el join, no en el ítem. Así que se pueden crear ítems que existan SOLO aquí
 * —contenido comercial puro— sin que aparezcan en el manual del huésped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_catalogo_has_item')]
#[ORM\UniqueConstraint(name: 'uniq_catalogo_item', columns: ['catalogo_id', 'item_id'])]
#[ORM\HasLifecycleCallbacks]
class PmsCatalogoHasItem
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: PmsCatalogo::class, inversedBy: 'catalogoHasItems')]
    #[ORM\JoinColumn(name: 'catalogo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsCatalogo $catalogo = null;

    /**
     * El contenido. `onDelete: CASCADE` porque sin ítem esta fila no significa
     * nada; borrar el ítem debe llevarse su colocación en el escaparate.
     */
    #[ORM\ManyToOne(targetEntity: PmsGuiaItem::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Hay que elegir un contenido.')]
    private ?PmsGuiaItem $item = null;

    /** En qué bloque del escaparate aparece. El orden de los bloques lo fija el enum. */
    #[ORM\Column(name: 'bloque', type: 'string', length: 20, enumType: PmsCatalogoBloque::class)]
    #[Assert\NotNull(message: 'Hay que elegir un bloque.')]
    private PmsCatalogoBloque $bloque = PmsCatalogoBloque::Descripcion;

    /** Posición DENTRO del bloque. Entre bloques manda el orden del enum. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /** Permite sacar algo del escaparate sin perder su colocación ni su orden. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getCatalogo(): ?PmsCatalogo { return $this->catalogo; }
    public function setCatalogo(?PmsCatalogo $catalogo): self { $this->catalogo = $catalogo; return $this; }

    public function getItem(): ?PmsGuiaItem { return $this->item; }
    public function setItem(?PmsGuiaItem $item): self { $this->item = $item; return $this; }

    public function getBloque(): PmsCatalogoBloque { return $this->bloque; }
    public function setBloque(PmsCatalogoBloque|string|null $bloque): self
    {
        $this->bloque = is_string($bloque)
            ? (PmsCatalogoBloque::tryFrom($bloque) ?? PmsCatalogoBloque::Descripcion)
            : ($bloque ?? PmsCatalogoBloque::Descripcion);

        return $this;
    }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    public function __toString(): string
    {
        return sprintf('%s · %s', $this->bloque->value, $this->item?->getNombreInterno() ?? 'sin contenido');
    }
}
