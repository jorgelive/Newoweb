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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * 🔍 SOLO LECTURA: lado inverso para saber en qué Componentes se está usando este término.
     * Sin cascade/orphanRemoval porque el dueño real de la relación es TravelComponenteItem.
     */
    #[ORM\OneToMany(mappedBy: 'diccionario', targetEntity: TravelComponenteItem::class)]
    private Collection $componenteItems;

    public function __toString(): string
    {
        return $this->nombreInterno ?? 'Sin nombre';
    }

    public function __construct()
    {
        $this->initializeId();
        $this->componenteItems = new ArrayCollection();
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

    /**
     * @return Collection<int, TravelComponenteItem>
     */
    public function getComponenteItems(): Collection
    {
        return $this->componenteItems;
    }

    // 🔥 VIRTUALES PARA EASYADMIN

    /**
     * Muestra el título en español directamente en la lista, sin abrir el JSON completo.
     * La estructura real de $titulo es: [{"language": "es", "content": "..."}, ...]
     */
    public function getVirtualTituloEs(): string
    {
        foreach ($this->titulo as $entrada) {
            if (($entrada['language'] ?? null) === 'es') {
                return $entrada['content'] ?? '—';
            }
        }

        return '—';
    }

    /**
     * Muestra los componentes (y su modo) donde se está usando este término del diccionario.
     * Colorea cada badge según el modo para una lectura más rápida.
     */
    public function getVirtualComponentesUsados(): string
    {
        if ($this->componenteItems->isEmpty()) {
            return '<span class="badge badge-secondary">Sin uso</span>';
        }

        $badges = [];

        foreach ($this->componenteItems as $item) {
            $componente = $item->getComponente();

            if (!$componente) {
                continue;
            }

            $modoNombre = $item->getModo()->name;

            [$icono, $colorFondo, $colorTexto] = match ($modoNombre) {
                'INCLUIDO' => ['✅', '#e6f4ea', '#1e7e34'],
                'NO_INCLUIDO' => ['❌', '#fde8e8', '#c0392b'],
                'OPCIONAL', 'UPSELL' => ['➕', '#fff4e0', '#b8860b'],
                'CORTESIA' => ['🎁', '#e8f0fe', '#2b5cad'],
                default => ['▪️', '#eeeeee', '#555555'],
            };

            $badges[] = sprintf(
                '<span style="display:inline-flex;align-items:center;gap:4px;background:%s;color:%s;'
                . 'border-radius:12px;padding:3px 10px;font-size:12px;font-weight:600;margin:2px 4px 2px 0;white-space:nowrap;">'
                . '%s %s <span style="opacity:0.7;font-weight:500;">· %s</span></span>',
                $colorFondo,
                $colorTexto,
                $icono,
                htmlspecialchars((string) $componente, ENT_QUOTES),
                htmlspecialchars($componente->getTipo()->value, ENT_QUOTES)
            );
        }

        if (!$badges) {
            return '<span class="badge badge-secondary">Sin uso</span>';
        }

        return sprintf(
            '<div style="display:flex;flex-wrap:wrap;max-width:420px;">%s</div>',
            implode('', $badges)
        );
    }
}