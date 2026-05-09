<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\NotaTipoEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cot_nota')]
#[ORM\HasLifecycleCallbacks]
class CotizacionNota
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Cotizacion::class, inversedBy: 'cotnotas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Cotizacion $cotizacion = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: NotaTipoEnum::class)]
    private NotaTipoEnum $tipo = NotaTipoEnum::INTRODUCCION;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json')]
    private array $tituloSnapshot = [];

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json')]
    private array $contenidoSnapshot = [];

    public function __construct()
    {
        $this->initializeId();
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    public function getCotizacion(): ?Cotizacion { return $this->cotizacion; }
    public function setCotizacion(?Cotizacion $cotizacion): self { $this->cotizacion = $cotizacion; return $this; }

    public function getTipo(): NotaTipoEnum { return $this->tipo; }
    public function setTipo(NotaTipoEnum $tipo): self { $this->tipo = $tipo; return $this; }

    public function getTituloSnapshot(): array { return $this->tituloSnapshot; }
    public function setTituloSnapshot(array $tituloSnapshot): self { $this->tituloSnapshot = $tituloSnapshot; return $this; }

    public function getContenidoSnapshot(): array { return $this->contenidoSnapshot; }
    public function setContenidoSnapshot(array $contenidoSnapshot): self { $this->contenidoSnapshot = $contenidoSnapshot; return $this; }
}