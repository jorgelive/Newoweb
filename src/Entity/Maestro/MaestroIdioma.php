<?php
declare(strict_types=1);

namespace App\Entity\Maestro;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'maestro_idioma')]
#[ORM\HasLifecycleCallbacks]
class MaestroIdioma
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null; // 'es', 'en'...

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $nombre = null;

    public function __construct(string $id, string $nombre)
    {
        $this->id = strtolower($id);
        $this->nombre = $nombre;
    }

    public function getId(): ?string { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function __toString(): string { return (string) $this->nombre; }
}