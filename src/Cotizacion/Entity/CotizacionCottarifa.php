<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * El recibo financiero inmutable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cottarifa')]
class CotizacionCottarifa
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotcomponente::class, inversedBy: 'cottarifas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotcomponente $cotcomponente = null;

    // 🔥 NUEVOS: Reflejando la estructura del Screenshot
    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombreSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $cantidad = 1;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montoCosto = '0.00';

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 10)]
    private string $moneda = 'USD';

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tarifaMaestraId = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $proveedorNombreSnapshot = null;

    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $tipoModalidadSnapshot = null;

    // 🔥 NIVEL 4: Detalles Operativos (Almacenado como JSON para rendimiento)
    #[Groups(['cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json')]
    private array $detallesOperativos = [];

    public function __construct()
    {
        $this->initializeId();
    }

    // --- GETTERS Y SETTERS EXPLÍCITOS ---

    public function getCotcomponente(): ?CotizacionCotcomponente
    {
        return $this->cotcomponente;
    }

    public function setCotcomponente(?CotizacionCotcomponente $cotcomponente): self
    {
        $this->cotcomponente = $cotcomponente;
        return $this;
    }

    public function getNombreSnapshot(): ?string
    {
        return $this->nombreSnapshot;
    }

    public function setNombreSnapshot(?string $nombreSnapshot): self
    {
        $this->nombreSnapshot = $nombreSnapshot;
        return $this;
    }

    public function getCantidad(): int
    {
        return $this->cantidad;
    }

    public function setCantidad(int $cantidad): self
    {
        $this->cantidad = $cantidad;
        return $this;
    }

    public function getMontoCosto(): string
    {
        return $this->montoCosto;
    }

    public function setMontoCosto(string $montoCosto): self
    {
        $this->montoCosto = $montoCosto;
        return $this;
    }

    public function getMoneda(): string
    {
        return $this->moneda;
    }

    public function setMoneda(string $moneda): self
    {
        $this->moneda = $moneda;
        return $this;
    }

    public function getTarifaMaestraId(): ?string
    {
        return $this->tarifaMaestraId;
    }

    public function setTarifaMaestraId(?string $tarifaMaestraId): self
    {
        $this->tarifaMaestraId = $tarifaMaestraId;
        return $this;
    }

    public function getProveedorNombreSnapshot(): ?string
    {
        return $this->proveedorNombreSnapshot;
    }

    public function setProveedorNombreSnapshot(?string $proveedorNombreSnapshot): self
    {
        $this->proveedorNombreSnapshot = $proveedorNombreSnapshot;
        return $this;
    }

    public function getTipoModalidadSnapshot(): ?string
    {
        return $this->tipoModalidadSnapshot;
    }

    public function setTipoModalidadSnapshot(?string $tipoModalidadSnapshot): self
    {
        $this->tipoModalidadSnapshot = $tipoModalidadSnapshot;
        return $this;
    }

    public function getDetallesOperativos(): array
    {
        return $this->detallesOperativos;
    }

    public function setDetallesOperativos(array $detallesOperativos): self
    {
        $this->detallesOperativos = $detallesOperativos;
        return $this;
    }
}