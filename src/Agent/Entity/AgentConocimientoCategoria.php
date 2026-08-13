<?php

declare(strict_types=1);

namespace App\Agent\Entity;

use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * El grupo temático por el que se acota una búsqueda de conocimiento: «pagos», «llegada»,
 * «normas de la casa», «qué llevar al tour».
 *
 * ── Para qué existe: la primera fase ────────────────────────────────────────
 * El conocimiento se consulta en DOS pasos. En el primero el modelo ve sólo esta lista —unas
 * pocas líneas— y elige de qué va la pregunta. En el segundo se le enseñan únicamente las
 * etiquetas de esa categoría. Sin este paso habría que mandarle la tabla entera en cada turno, y
 * la tabla está pensada para crecer mucho: es alimentación continua, no una carga inicial.
 *
 * ── Por qué es una TABLA y no un enum ───────────────────────────────────────
 * Porque se alimenta desde el panel, con el tiempo y sin programador. Un enum obligaría a un
 * despliegue por cada categoría nueva, y el día que hay que añadir «reclamos» a las nueve de la
 * noche eso significa no añadirla.
 *
 * ── Id natural, como los demás maestros ─────────────────────────────────────
 * `pagos`, `llegada`… El id es legible a propósito: aparece en los logs del triaje y en el
 * prompt, y un uuid ahí no dice nada a quien lee por qué el agente eligió lo que eligió.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_conocimiento_categoria')]
#[ORM\HasLifecycleCallbacks]
class AgentConocimientoCategoria
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    private string $id = '';

    /** Cómo se le presenta al modelo: «Pagos y facturación». Corto, que se lee en el prompt. */
    #[ORM\Column(type: 'string', length: 120)]
    private string $nombre = '';

    /**
     * Una línea que ayuda al modelo a elegir bien cuando dos categorías se parecen.
     *
     * Vacío es válido: si el nombre ya lo dice todo, añadir texto sólo gasta contexto.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $pista = null;

    /** Apagar una categoría la saca de la primera fase sin borrar lo que cuelga de ella. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activa = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /** @var Collection<int, AgentConocimiento> */
    #[ORM\OneToMany(mappedBy: 'categoria', targetEntity: AgentConocimiento::class)]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombre ?: $this->id;
    }

    /** La línea que ve el modelo en la primera fase. */
    public function comoLinea(): string
    {
        return $this->pista === null || $this->pista === ''
            ? sprintf('- %s: %s', $this->id, $this->nombre)
            : sprintf('- %s: %s (%s)', $this->id, $this->nombre, $this->pista);
    }

    public function getId(): string { return $this->id; }
    public function setId(string $id): self { $this->id = $id; return $this; }

    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getPista(): ?string { return $this->pista; }
    public function setPista(?string $pista): self { $this->pista = $pista; return $this; }

    public function isActiva(): bool { return $this->activa; }
    public function setActiva(bool $activa): self { $this->activa = $activa; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    /** @return Collection<int, AgentConocimiento> */
    public function getItems(): Collection { return $this->items; }
}
