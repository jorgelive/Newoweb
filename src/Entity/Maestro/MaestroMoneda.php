<?php
declare(strict_types=1);

namespace App\Entity\Maestro;

use App\Entity\Trait\TimestampTrait;
use App\Repository\Maestro\MaestroMonedaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups; // 🔥 IMPRESCINDIBLE

#[ORM\Entity(repositoryClass: MaestroMonedaRepository::class)]
#[ORM\Table(name: 'maestro_moneda')]
#[ORM\HasLifecycleCallbacks]
class MaestroMoneda
{
    public const DB_ID_SOL = 'PEN';
    public const DB_ID_USD = 'USD';

    use TimestampTrait;

    // 🔥 Agregamos los grupos para que la Tarifa pueda exponer estos datos
    #[Groups(['componente:item:read', 'cotizacion:read', 'cotizacion:item:read'])]
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 3)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null; // 'PEN', 'USD'...

    #[Groups(['componente:item:read', 'cotizacion:read', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $nombre = null;

    #[Groups(['componente:item:read', 'cotizacion:read', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 5)]
    private ?string $simbolo = null;

    public function __construct(string $id, string $nombre, string $simbolo)
    {
        $this->id = strtoupper($id);
        $this->nombre = $nombre;
        $this->simbolo = $simbolo;
    }

    public function getId(): ?string { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getSimbolo(): ?string { return $this->simbolo; }
    public function setSimbolo(string $simbolo): self { $this->simbolo = $simbolo; return $this; }

    public function __toString(): string { return sprintf('%s (%s)', $this->nombre, $this->simbolo); }
}