<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiProperty;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Travel\Enum\TarifaProcedenciaEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'travel_tarifa')]
class TravelTarifa
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    // 🚫 CORTE CIRCULAR
    #[ORM\ManyToOne(targetEntity: TravelComponente::class, inversedBy: 'tarifas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?TravelComponente $componente = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $monto = '0.00';

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?MaestroMoneda $moneda = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: TarifaModalidadEnum::class, nullable: true)]
    private ?TarifaModalidadEnum $modalidad = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: TarifaProcedenciaEnum::class, nullable: true)]
    private ?TarifaProcedenciaEnum $procedencia = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMinima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $edadMaxima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMinima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $capacidadMaxima = null;

    #[Groups(['componente:item:read', 'componente:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $costoPorGrupo = false;

    public function __construct()
    {
        $this->initializeId();
    }

    /**
     * 🔥 Obligatorio para el Deep Clone de la entidad Padre.
     * Al usar clone desde el padre, este método garantiza que la tarifa
     * nazca como un registro 100% nuevo en la base de datos.
     */
    public function __clone()
    {
        $this->resetId();
        $this->resetTimestamps();
    }
    /**
     * Representación en texto para EasyAdmin y depuración.
     * Genera una etiqueta visual que resume el costo, la moneda y las reglas de negocio.
     * Ejemplos: "🏷️ Adulto | USD 84.00 👤 [Por Pax]" o "🏷️ Van Privada | PEN 150.00 👥 [Por Grupo]"
     */
    public function __toString(): string
    {
        if (!$this->nombreInterno) {
            return '✨ Nueva Tarifa';
        }

        $monedaStr = $this->moneda ? (string) $this->moneda : '';
        $montoStr = $this->monto !== null ? $this->monto : '0.00';

        // Etiqueta base
        $etiqueta = sprintf('🏷️ %s | %s %s', $this->nombreInterno, $monedaStr, $montoStr);

        // Indicador visual de la matemática (cómo se cobra)
        if ($this->costoPorGrupo) {
            $etiqueta .= ' 👥 [Por Grupo]';
        } else {
            $etiqueta .= ' 👤 [Por Pax]';
        }

        // Indicador visual de restricciones de edad (para evitar errores operativos)
        if ($this->edadMinima !== null || $this->edadMaxima !== null) {
            $min = $this->edadMinima ?? '0';
            $max = $this->edadMaxima ?? '∞';
            $etiqueta .= sprintf(' 🎂 (%s-%s años)', $min, $max);
        }

        return $etiqueta;
    }

    // ... (Getters y Setters respetando tipado estricto)
    public function getMonto(): ?string
    {
        return $this->monto;
    }

    public function setMonto(string $monto): self
    {
        $this->monto = $monto;
        return $this;
    }

    public function getComponente(): ?TravelComponente
    {
        return $this->componente;
    }

    public function setComponente(?TravelComponente $componente): void
    {
        $this->componente = $componente;
    }

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(?string $nombreInterno): void
    {
        $this->nombreInterno = $nombreInterno;
    }

    public function getTitulo(): array
    {
        return $this->titulo;
    }

    public function setTitulo(array $titulo): void
    {
        $this->titulo = $titulo;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }

    public function setMoneda(?MaestroMoneda $moneda): void
    {
        $this->moneda = $moneda;
    }

    public function getModalidad(): ?TarifaModalidadEnum
    {
        return $this->modalidad;
    }

    public function setModalidad(?TarifaModalidadEnum $modalidad): self
    {
        $this->modalidad = $modalidad;
        return $this;
    }

    public function getProcedencia(): ?TarifaProcedenciaEnum
    {
        return $this->procedencia;
    }

    public function setProcedencia(?TarifaProcedenciaEnum $procedencia): self
    {
        $this->procedencia = $procedencia;
        return $this;
    }

    public function getEdadMinima(): ?int
    {
        return $this->edadMinima;
    }

    public function setEdadMinima(?int $edadMinima): self
    {
        $this->edadMinima = $edadMinima;
        return $this;
    }

    public function getEdadMaxima(): ?int
    {
        return $this->edadMaxima;
    }

    public function setEdadMaxima(?int $edadMaxima): self
    {
        $this->edadMaxima = $edadMaxima;
        return $this;
    }

    public function getCapacidadMinima(): ?int
    {
        return $this->capacidadMinima;
    }

    public function setCapacidadMinima(?int $capacidadMinima): void
    {
        $this->capacidadMinima = $capacidadMinima;
    }

    public function getCapacidadMaxima(): ?int
    {
        return $this->capacidadMaxima;
    }

    public function setCapacidadMaxima(?int $capacidadMaxima): void
    {
        $this->capacidadMaxima = $capacidadMaxima;
    }

    /**
     * Verifica si la tarifa se cobra por bloque/grupo en lugar de por pasajero.
     */
    public function isCostoPorGrupo(): bool
    {
        return $this->costoPorGrupo;
    }

    /**
     * Establece si la tarifa se cobra por bloque/grupo.
     */
    public function setCostoPorGrupo(bool $costoPorGrupo): self
    {
        $this->costoPorGrupo = $costoPorGrupo;
        return $this;
    }
}