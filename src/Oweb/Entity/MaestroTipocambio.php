<?php
namespace App\Oweb\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'mae_tipocambio')]
#[ORM\Entity]
class MaestroTipocambio
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity:MaestroMoneda::class, inversedBy: 'tipocambios')]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    protected ?MaestroMoneda $moneda;

    #[ORM\Column(name: 'fecha', type: 'date')]
    private ?DateTime $fecha;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private ?string $compra;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    private ?string $venta;

    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $creado;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $modificado;

    public function __toString(): string
    {
        if(is_null($this->getFecha())) {
            return sprintf("Id: %s.", $this->getId());
        }

        return $this->getFecha()->format('Y-m-d');
    }

    /**
     * Calcula el promedio exacto con 3 decimales usando BCMath.
     * * @example "3.354" + "3.363" = "6.717" / 2 = "3.358"
     */
    public function getPromedio(): string
    {
        $compra = $this->getCompra() ?? '0.000';
        $venta = $this->getVenta() ?? '0.000';

        // Sumamos compra y venta
        $suma = bcadd($compra, $venta, 3);

        // Dividimos entre 2
        return bcdiv($suma, '2', 3);
    }

    /**
     * Calcula el promedio y lo redondea a 2 decimales.
     * * @example "3.3585" -> "3.36"
     */
    public function getPromedioredondeado(): string
    {
        // Usamos el método anterior para obtener la base
        $promedio = $this->getPromedio();

        // Round en PHP funciona bien para presentación final (2 decimales)
        // pero lo casteamos a float solo en el último paso.
        return (string) round((float) $promedio, 2);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setFecha(DateTime $fecha): self
    {
        $this->fecha = $fecha;

        return $this;
    }

    public function getFecha(): ?DateTime
    {
        return $this->fecha;
    }

    public function setCompra(?string $compra): self
    {
        $this->compra = $compra;

        return $this;
    }

    public function getCompra(): ?string
    {
        return $this->compra;
    }

    public function setVenta(?string $venta): self
    {
        $this->venta = $venta;

        return $this;
    }

    public function getVenta(): ?string
    {
        return $this->venta;
    }

    public function setCreado(?DateTime $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getCreado(): ?DateTime
    {
        return $this->creado;
    }

    public function setModificado(?DateTime $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getModificado(): ?DateTime
    {
        return $this->modificado;
    }

    public function setMoneda(?MaestroMoneda $moneda): self
    {
        $this->moneda = $moneda;

        return $this;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }
}
