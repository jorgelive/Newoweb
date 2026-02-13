<?php

declare(strict_types=1);

namespace App\Pax\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'pax_ui_i18n')]
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/public/pax/ui_i18n/{id}'
        ),
        new GetCollection(
            uriTemplate: '/public/pax/ui_i18n'
        )
    ],
    normalizationContext: ['groups' => ['pax:read']],
    order: ['scope' => 'DESC', 'id' => 'ASC']
)]
#[ApiFilter(RangeFilter::class, properties: ['scope'])] // Permite ?scope=scope
#[ApiFilter(OrderFilter::class, properties: ['prioridad', 'nombre'])]
#[ORM\HasLifecycleCallbacks]
class UiI18n
{
    use TimestampTrait;
    use AutoTranslateControlTrait;

    /**
     * Natural Key: El identificador único de la traducción (ej: 'res_checkin')
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $id;

    /**
     * Agrupador para facilitar la carga por secciones (Reserva, Guia, etc.)
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $scope;

    /**
     * Array de Objetos: [{"language": "es", "content": "..."}]
     */
    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es')]
    private array $contenido = [];

    public function __construct(string $id, string $scope)
    {
        $this->id = $id;
        $this->scope = $scope;
    }

    #[Groups(['pax:read'])]
    public function getId(): string
    {
        return $this->id;
    }
    // No hay setId porque es Natural Key definida en el constructor

    #[Groups(['pax:read'])]
    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    #[Groups(['pax:read'])]
    public function getContenido(): array
    {
        // Ordenamos por la jerarquía definida en MaestroIdioma antes de entregar
        return MaestroIdioma::ordenarParaFormulario($this->contenido);
    }

    public function setContenido(array $contenido): self
    {
        // Validamos y normalizamos el array de objetos antes de guardar
        $this->contenido = MaestroIdioma::normalizarParaDB($contenido);
        return $this;
    }
}