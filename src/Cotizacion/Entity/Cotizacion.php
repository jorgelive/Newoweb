<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use App\Attribute\AutoTranslate;
use App\Cotizacion\Enum\CotizacionEstadoEnum;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    shortName: 'Cotizacion',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Put(),
        new Patch(),
        new Delete()
    ],
    routePrefix: '/sales',
    normalizationContext: ['groups' => ['cotizacion:read', 'timestamp:read']],
    denormalizationContext: ['groups' => ['cotizacion:write']]
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_cotizacion')]
#[ORM\HasLifecycleCallbacks]
class Cotizacion
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'string', length: 30, enumType: CotizacionEstadoEnum::class, options: ['default' => 'Pendiente'])]
    private CotizacionEstadoEnum $estado = CotizacionEstadoEnum::PENDIENTE;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $numPax = 1;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, options: ['default' => '20.00'])]
    private string $comision = '20.00';

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $adelanto = '0.00';

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hotelOculto = false;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $precioOculto = false;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $resumen = [];

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fechaExpiracion = null;

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'USD'])]
    private string $monedaGlobal = 'USD';

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 5, options: ['default' => 'es'])]
    private string $idiomaCliente = 'es';

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalCosto = '0.00';

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalVenta = '0.00';

    // 🔥 EL NUEVO CAMPO DE TIPO DE CAMBIO
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, options: ['default' => '1.0000'])]
    private string $tipoCambio = '1.0000';

    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $clasificacionFinanciera = null;

    /**
     * @var Collection<int, CotizacionCotservicio>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionCotservicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaInicioAbsoluta' => 'ASC'])]
    private Collection $cotservicios;

    /**
     * @var Collection<int, CotizacionNota>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write'])]
    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionNota::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $cotnotas;

    public function __construct()
    {
        $this->initializeId();
        $this->cotservicios = new ArrayCollection();
        $this->cotnotas = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('V%d - %s', $this->version, $this->file ? $this->file->getNombreGrupo() : 'Sin File');
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'file:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    #[Groups(['cotizacion:write'])]
    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    #[Groups(['cotizacion:write'])]
    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;
        return $this;
    }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getVersion(): int { return $this->version; }
    public function setVersion(int $version): self { $this->version = $version; return $this; }

    public function getFechaExpiracion(): ?\DateTimeImmutable { return $this->fechaExpiracion; }
    public function setFechaExpiracion(?\DateTimeImmutable $fechaExpiracion): self { $this->fechaExpiracion = $fechaExpiracion; return $this; }

    public function getMonedaGlobal(): string { return $this->monedaGlobal; }
    public function setMonedaGlobal(string $monedaGlobal): self { $this->monedaGlobal = $monedaGlobal; return $this; }

    public function getIdiomaCliente(): string { return $this->idiomaCliente; }
    public function setIdiomaCliente(string $idiomaCliente): self { $this->idiomaCliente = $idiomaCliente; return $this; }

    public function getTotalCosto(): string { return $this->totalCosto; }
    public function setTotalCosto(string $totalCosto): self { $this->totalCosto = $totalCosto; return $this; }

    public function getTotalVenta(): string { return $this->totalVenta; }
    public function setTotalVenta(string $totalVenta): self { $this->totalVenta = $totalVenta; return $this; }

    public function getTipoCambio(): string { return $this->tipoCambio; }
    public function setTipoCambio(string $tipoCambio): self { $this->tipoCambio = $tipoCambio; return $this; }

    public function getClasificacionFinanciera(): ?array { return $this->clasificacionFinanciera; }
    public function setClasificacionFinanciera(?array $clasificacionFinanciera): self { $this->clasificacionFinanciera = $clasificacionFinanciera; return $this; }

    public function getCotservicios(): Collection { return $this->cotservicios; }
    public function addCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if (!$this->cotservicios->contains($cotservicio)) {
            $this->cotservicios->add($cotservicio);
            $cotservicio->setCotizacion($this);
        }
        return $this;
    }
    public function removeCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if ($this->cotservicios->removeElement($cotservicio)) {
            if ($cotservicio->getCotizacion() === $this) { $cotservicio->setCotizacion(null); }
        }
        return $this;
    }

    public function getCotnotas(): Collection { return $this->cotnotas; }
    public function addCotnota(CotizacionNota $cotnota): self
    {
        if (!$this->cotnotas->contains($cotnota)) {
            $this->cotnotas->add($cotnota);
            $cotnota->setCotizacion($this);
        }
        return $this;
    }
    public function removeCotnota(CotizacionNota $cotnota): self
    {
        if ($this->cotnotas->removeElement($cotnota)) {
            if ($cotnota->getCotizacion() === $this) { $cotnota->setCotizacion(null); }
        }
        return $this;
    }

    public function getEstado(): CotizacionEstadoEnum { return $this->estado; }
    public function setEstado(CotizacionEstadoEnum $estado): self { $this->estado = $estado; return $this; }

    public function getNumPax(): int { return $this->numPax; }
    public function setNumPax(int $numPax): void { $this->numPax = $numPax; }

    public function getComision(): string { return $this->comision; }
    public function setComision(string $comision): void { $this->comision = $comision; }

    public function getAdelanto(): string { return $this->adelanto; }
    public function setAdelanto(string $adelanto): void { $this->adelanto = $adelanto; }

    public function isHotelOculto(): bool { return $this->hotelOculto; }
    public function setHotelOculto(bool $hotelOculto): void { $this->hotelOculto = $hotelOculto; }

    public function isPrecioOculto(): bool { return $this->precioOculto; }
    public function setPrecioOculto(bool $precioOculto): void { $this->precioOculto = $precioOculto; }

    public function getResumen(): array { return $this->resumen; }
    public function setResumen(array $resumen): void { $this->resumen = $resumen; }
}