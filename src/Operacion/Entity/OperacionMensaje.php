<?php

declare(strict_types=1);

namespace App\Operacion\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Get(
            security: "is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),
        new Post(
            securityPostDenormalize: "is_granted('" . Roles::OPERACIONES_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para registrar mensajes.'
        ),
        new Delete(
            security: "is_granted('" . Roles::OPERACIONES_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar mensajes.'
        ),
    ],
    routePrefix: '/ops',
    normalizationContext: ['groups' => ['operacion:mensaje:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['operacion:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['ordenServicio' => 'exact'])]
#[ORM\Entity]
#[ORM\Table(name: 'operacion_mensaje')]
#[ORM\HasLifecycleCallbacks]
class OperacionMensaje
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['operacion:mensaje:read', 'operacion:write'])]
    #[ORM\ManyToOne(targetEntity: OperacionOrdenServicio::class, inversedBy: 'mensajes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?OperacionOrdenServicio $ordenServicio = null;

    #[Groups(['operacion:mensaje:read', 'operacion:write'])]
    #[ORM\Column(type: 'string', length: 50)]
    private string $tipo;

    #[Groups(['operacion:mensaje:read', 'operacion:write'])]
    #[ORM\Column(type: 'text')]
    private string $cuerpoHtml;

    #[Groups(['operacion:mensaje:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $usuarioId = null;

    public function __construct()
    {
        $this->initializeId();
    }

    #[Groups(['operacion:mensaje:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getOrdenServicio(): ?OperacionOrdenServicio { return $this->ordenServicio; }
    public function setOrdenServicio(?OperacionOrdenServicio $ordenServicio): self { $this->ordenServicio = $ordenServicio; return $this; }

    public function getTipo(): string { return $this->tipo; }
    public function setTipo(string $tipo): self { $this->tipo = $tipo; return $this; }

    public function getCuerpoHtml(): string { return $this->cuerpoHtml; }
    public function setCuerpoHtml(string $cuerpoHtml): self { $this->cuerpoHtml = $cuerpoHtml; return $this; }

    public function getUsuarioId(): ?string { return $this->usuarioId; }
    public function setUsuarioId(?string $usuarioId): self { $this->usuarioId = $usuarioId; return $this; }
}
