<?php
declare(strict_types=1);

namespace App\Entity\Maestro;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\Maestro\MaestroMonedaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'Moneda',
    operations: [
        new Get(),
        new GetCollection(uriTemplate: '/monedas'),
    ],
    routePrefix: '/maestro',
    normalizationContext: ['groups' => ['maestro:moneda:read']],
)]
#[ORM\Entity(repositoryClass: MaestroMonedaRepository::class)]
#[ORM\Table(name: 'maestro_moneda')]
#[ORM\HasLifecycleCallbacks]
class MaestroMoneda
{
    public const DB_ID_SOL = 'PEN';
    public const DB_ID_USD = 'USD';

    use TimestampTrait;
    use AutoTranslateControlTrait;

    // 🔥 Agregamos los grupos para que la Tarifa pueda exponer estos datos
    #[Groups(['componente:item:read', 'cotizacion:read', 'cotizacion:item:read', 'operacion:read', 'operacion:item:read', 'maestro:moneda:read'])]
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 3)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null; // 'PEN', 'USD'...

    #[Groups(['componente:item:read', 'cotizacion:read', 'cotizacion:item:read', 'operacion:read', 'operacion:item:read', 'maestro:moneda:read'])]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $nombre = null;

    #[Groups(['componente:item:read', 'cotizacion:read', 'cotizacion:item:read', 'operacion:read', 'operacion:item:read', 'maestro:moneda:read'])]
    #[ORM\Column(type: 'string', length: 5)]
    private ?string $simbolo = null;

    /**
     * El nombre de la moneda para el CLIENTE, en cada idioma: «Soles», «Dollars»… (16/10/2026).
     *
     * `nombre` se queda como estaba —español plano, lo leen el panel y las operaciones—. Esto es
     * lo que ve quien paga: en Perú nadie dice «PEN», y el código ISO en la ficha de una cuenta se
     * leía como una sigla más del banco. Lo pinta `pax` (`maestroStore.nombreMoneda()`).
     *
     * ⚠️ Una palabra suelta la traduce mal el traductor automático, así que los idiomas se fijan a
     * mano con `app:maestro:monedas:titulos`, y el sol no se traduce: «Soles» en todos.
     *
     * @var list<array{language?: string, content?: string|null, origenHash?: string}>
     */
    #[Groups(['maestro:moneda:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    public function __construct(string $id, string $nombre, string $simbolo)
    {
        $this->id = strtoupper($id);
        $this->nombre = $nombre;
        $this->simbolo = $simbolo;
    }

    public function getId(): ?string { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    /** @return list<array{language?: string, content?: string|null, origenHash?: string}> */
    public function getTitulo(): array { return $this->titulo; }
    /** @param list<array{language?: string, content?: string|null, origenHash?: string}> $titulo */
    public function setTitulo(array $titulo): self { $this->titulo = $titulo; return $this; }

    public function getSimbolo(): ?string { return $this->simbolo; }
    public function setSimbolo(string $simbolo): self { $this->simbolo = $simbolo; return $this; }

    public function __toString(): string { return sprintf('%s (%s)', $this->nombre, $this->simbolo); }
}