<?php

namespace App\Pms\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity]
#[ORM\Table(name: 'pms_beds24_endpoint')]
class PmsBeds24Endpoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private ?string $accion = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $endpoint = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $metodo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $descripcion = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $activo = null;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $created = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $updated = null;

    /**
     * Colas asociadas a este endpoint.
     *
     * Útil para:
     * - debug desde el admin (ver “qué colas usan este endpoint”)
     * - reportes (fallos por endpoint)
     * - futuras políticas de batching por endpoint
     *
     * Nota:
     * - orphanRemoval=false porque borrar un endpoint no debería borrar colas históricas.
     * - cascade NO se necesita: la cola es la owning side (ManyToOne) y se persiste aparte.
     *
     * @var Collection<int, PmsBeds24LinkQueue>
     */
    #[ORM\OneToMany(mappedBy: 'endpoint', targetEntity: PmsBeds24LinkQueue::class)]
    private Collection $queues;

    public function __construct()
    {
        $this->queues = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccion(): ?string
    {
        return $this->accion;
    }

    public function setAccion(?string $accion): self
    {
        $this->accion = $accion;

        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getMetodo(): ?string
    {
        return $this->metodo;
    }

    public function setMetodo(?string $metodo): self
    {
        $this->metodo = $metodo;

        return $this;
    }

    public function getDescripcion(): ?string
    {
        return $this->descripcion;
    }

    public function setDescripcion(?string $descripcion): self
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function isActivo(): ?bool
    {
        return $this->activo;
    }

    public function setActivo(?bool $activo): self
    {
        $this->activo = $activo;

        return $this;
    }

    public function getCreated(): ?DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    /**
     * @return Collection<int, PmsBeds24LinkQueue>
     */
    public function getQueues(): Collection
    {
        return $this->queues;
    }

    public function addQueue(PmsBeds24LinkQueue $queue): self
    {
        if (!$this->queues->contains($queue)) {
            $this->queues->add($queue);

            // Mantener consistencia del lado owning.
            // Esto ayuda cuando se construyen objetos en memoria (tests, fixtures, admin).
            if ($queue->getEndpoint() !== $this) {
                $queue->setEndpoint($this);
            }
        }

        return $this;
    }

    public function removeQueue(PmsBeds24LinkQueue $queue): self
    {
        if ($this->queues->removeElement($queue)) {
            // No hacemos setEndpoint(null) porque:
            // - la JoinColumn en queue es NOT NULL
            // - normalmente no “despegas” colas de un endpoint, se reasignan creando otra cola.
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->accion ?? ('Endpoint #' . $this->id);
    }
}