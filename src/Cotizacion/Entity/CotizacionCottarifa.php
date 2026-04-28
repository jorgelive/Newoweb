<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;

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

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $montoCosto = '0.00';

    #[ORM\Column(type: 'string', length: 10)]
    private string $moneda = 'USD';

    /**
     * Rastro para saber de qué tarifa maestra en el catálogo proviene este precio (opcional).
     */
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $tarifaMaestraId = null;

    public function __construct()
    {
        $this->initializeId();
    }

    public function getCotcomponente(): ?CotizacionCotcomponente
    {
        return $this->cotcomponente;
    }

    public function setCotcomponente(?CotizacionCotcomponente $cotcomponente): self
    {
        $this->cotcomponente = $cotcomponente;
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
}
