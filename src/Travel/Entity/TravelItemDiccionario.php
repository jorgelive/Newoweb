<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'Diccionario', // 🔥 Define el recurso base para generar '/diccionarios'
    operations: [
        // Genera: GET /travel/diccionarios
        new GetCollection(
            normalizationContext: ['groups' => ['diccionario:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: GET /travel/diccionarios/{id}
        new Get(
            normalizationContext: ['groups' => ['diccionario:item:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "')"
        ),

        // Genera: POST /travel/diccionarios
        new Post(
            denormalizationContext: ['groups' => ['diccionario:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear elementos del diccionario.'
        ),

        // Genera: PUT /travel/diccionarios/{id}
        new Put(
            denormalizationContext: ['groups' => ['diccionario:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar elementos.'
        ),

        // Genera: DELETE /travel/diccionarios/{id}
        new Delete(
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar elementos.'
        )
    ],   // 🔥 Agrupa todas las rutas bajo el módulo logístico
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_item_diccionario')]
#[ORM\HasLifecycleCallbacks]
class TravelItemDiccionario
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['diccionario:read', 'diccionario:item:read', 'diccionario:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['diccionario:read', 'diccionario:item:read', 'diccionario:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    public function __toString(): string
    {
        return $this->nombreInterno ?? 'Sin nombre';
    }

    public function __construct()
    {
        $this->initializeId();
    }

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(string $nombreInterno): self
    {
        $this->nombreInterno = $nombreInterno;
        return $this;
    }

    public function getTitulo(): array
    {
        return $this->titulo;
    }

    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }
}