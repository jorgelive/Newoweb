<?php
declare(strict_types=1);

namespace App\Entity\Maestro;

use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'maestro_documento_tipo')]
#[ORM\HasLifecycleCallbacks]
class MaestroDocumentoTipo
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private ?string $id = null; // Código SUNAT (01, 04, 07...)

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigoMc = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $codigoConsettur = null;

    public function __construct(string $id, string $nombre)
    {
        $this->id = $id;
        $this->nombre = $nombre;
    }

    public function getId(): ?string { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getCodigoMc(): ?int { return $this->codigoMc; }
    public function setCodigoMc(?int $val): self { $this->codigoMc = $val; return $this; }

    public function getCodigoConsettur(): ?int { return $this->codigoConsettur; }
    public function setCodigoConsettur(?int $val): self { $this->codigoConsettur = $val; return $this; }

    public function __toString(): string { return (string) $this->nombre; }
}