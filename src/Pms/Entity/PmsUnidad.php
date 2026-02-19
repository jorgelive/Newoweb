<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Entidad PmsUnidad.
 * Representa un apartamento o habitación específica.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_unidad')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsUnidad
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait; // 👈 Agrega control de traducción y sobreescritura

    use MediaTrait;

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'unidades')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false, columnDefinition: 'BINARY(16)')]
    private ?PmsEstablecimiento $establecimiento = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[Vich\UploadableField(mapping: 'unidad_images', fileNameProperty: 'imageName')]
    #[Assert\File(
        maxSize: "5M",
        mimeTypes: ["image/jpeg", "image/png", "image/webp", "application/pdf"],
        mimeTypesMessage: "Formato no válido. Use JPG, PNG, WEBP o PDF."
    )]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $imageUpdatedAt = null;

    /**
     * PROPIEDAD VIRTUAL (No es columna de DB).
     * El AssetListener inyectará aquí la URL pública completa.
     * Usado por LiipImageField en EasyAdmin.
     */
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $codigoInterno = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $capacidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    // ============================================================
    // 🔐 SEGURIDAD Y CONECTIVIDAD
    // ============================================================

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoPuerta = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $codigoCaja = null;

    /**
     * Almacena múltiples redes WiFi.
     * Estructura JSON:
     * [
     * {
     * "ssid": "Wifi_A",
     * "password": "123",
     * "ubicacion": [
     * {"language": "es", "content": "Salón"},
     * {"language": "en", "content": "Living Room"}
     * ]
     * }
     * ]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', nestedFields: ['ubicacion'])] // 👈 Traducción anidada configurada
    private ?array $wifiNetworks = [];

    // ============================================================
    // 💰 TARIFA BASE
    // ============================================================

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: false, options: ['default' => '0.00'])]
    private string $tarifaBasePrecio = '0.00';

    #[ORM\Column(type: 'smallint', options: ['default' => 2], nullable: false)]
    private int $tarifaBaseMinStay = 2;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'tarifa_base_moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $tarifaBaseMoneda = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $tarifaBaseActiva = true;

    // ============================================================
    // 🔗 RELACIONES
    // ============================================================

    /** @var Collection<int, PmsUnidadBeds24Map> */
    #[ORM\OneToMany(mappedBy: 'pmsUnidad', targetEntity: PmsUnidadBeds24Map::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $beds24Maps;

    /** @var Collection<int, PmsRatesPushQueue> */
    #[ORM\OneToMany(mappedBy: 'unidad', targetEntity: PmsRatesPushQueue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tarifaQueues;

    /** @var Collection<int, PmsBookingsPullQueue> */
    #[ORM\ManyToMany(targetEntity: PmsBookingsPullQueue::class, mappedBy: 'unidades')]
    private Collection $bookingsPullQueues;

    #[ORM\OneToOne(mappedBy: 'unidad', targetEntity: PmsGuia::class)]
    private ?PmsGuia $guia = null;


    public function __construct()
    {
        $this->beds24Maps = new ArrayCollection();
        $this->tarifaQueues = new ArrayCollection();
        $this->bookingsPullQueues = new ArrayCollection();
        $this->wifiNetworks = []; // Inicializamos array vacío
        $this->id = Uuid::v7();
    }

    // ============================================================
    // GETTERS Y SETTERS BÁSICOS
    // ============================================================

    public function getEstablecimiento(): ?PmsEstablecimiento { return $this->establecimiento; }
    public function setEstablecimiento(?PmsEstablecimiento $val): self { $this->establecimiento = $val; return $this; }

    #[Groups(['pax_reserva:read'])]
    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $val): self { $this->nombre = $val; return $this; }

    #[Groups(['pax_reserva:read', 'pax_evento:read'])]
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $url): self { $this->imageUrl = $url; return $this; }

    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if (null !== $imageFile) {
            $this->imageUpdatedAt = new DateTimeImmutable();
        }
    }
    public function getImageFile(): ?File { return $this->imageFile; }

    public function getImageName(): ?string { return $this->imageName; }
    public function setImageName(?string $imageName): void { $this->imageName = $imageName; }

    public function getCodigoInterno(): ?string { return $this->codigoInterno; }
    public function setCodigoInterno(?string $val): self { $this->codigoInterno = $val; return $this; }

    public function getCapacidad(): ?int { return $this->capacidad; }
    public function setCapacidad(?int $val): self { $this->capacidad = $val; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $val): self { $this->activo = $val; return $this; }

    // ============================================================
    // 🔐 GETTERS Y SETTERS DE SEGURIDAD
    // ============================================================

    public function getCodigoPuerta(): ?string
    {
        return $this->codigoPuerta;
    }

    public function setCodigoPuerta(?string $codigoPuerta): self
    {
        $this->codigoPuerta = $codigoPuerta;
        return $this;
    }

    public function getCodigoCaja(): ?string
    {
        return $this->codigoCaja;
    }

    public function setCodigoCaja(?string $codigoCaja): self
    {
        $this->codigoCaja = $codigoCaja;
        return $this;
    }

    public function getWifiNetworks(): array
    {
        $networks = $this->wifiNetworks ?? [];

        // Recorremos cada red para ordenar su "ubicacion"
        foreach ($networks as &$network) {
            if (isset($network['ubicacion']) && is_array($network['ubicacion'])) {
                $network['ubicacion'] = MaestroIdioma::ordenarParaFormulario($network['ubicacion']);
            }
        }

        return $networks;
    }

    public function setWifiNetworks(?array $wifiNetworks): self
    {
        // Importante: array_values asegura que se guarde como lista JSON [{},{}]
        // y no como objeto {"0":{}, "2":{}} si se borraron elementos intermedios.
        $this->wifiNetworks = $wifiNetworks ? array_values($wifiNetworks) : [];
        return $this;
    }

    /**
     * Helper: Obtiene el SSID de la primera red (Principal).
     * Útil para fallback si solo se muestra una red.
     */
    public function getMainWifiSsid(): string
    {
        return $this->wifiNetworks[0]['ssid'] ?? '';
    }

    /**
     * Helper: Obtiene el Password de la primera red (Principal).
     */
    public function getMainWifiPass(): string
    {
        return $this->wifiNetworks[0]['password'] ?? '';
    }

    // ============================================================
    // 💰 TARIFA BASE
    // ============================================================

    public function getTarifaBasePrecio(): string { return $this->tarifaBasePrecio; }
    public function setTarifaBasePrecio(string $val): self { $this->tarifaBasePrecio = $val; return $this; }

    public function getTarifaBaseMinStay(): int { return $this->tarifaBaseMinStay; }
    public function setTarifaBaseMinStay(int $val): self { $this->tarifaBaseMinStay = $val; return $this; }

    public function getTarifaBaseMoneda(): ?MaestroMoneda { return $this->tarifaBaseMoneda; }

    public function getTarifaBaseMonedaOrFail(): MaestroMoneda
    {
        if ($this->tarifaBaseMoneda === null) {
            throw new \LogicException('Tarifa base activa sin moneda definida en la unidad #' . ($this->id ?? 'new'));
        }
        return $this->tarifaBaseMoneda;
    }
    public function setTarifaBaseMoneda(?MaestroMoneda $val): self { $this->tarifaBaseMoneda = $val; return $this; }

    public function isTarifaBaseActiva(): bool { return $this->tarifaBaseActiva; }
    public function setTarifaBaseActiva(bool $val): self { $this->tarifaBaseActiva = $val; return $this; }

    // ============================================================
    // GESTIÓN DE RELACIONES (Maps, Queues, Guia)
    // ============================================================

    public function getBeds24Maps(): Collection { return $this->beds24Maps; }

    public function addBeds24Map(PmsUnidadBeds24Map $map): self
    {
        if (!$this->beds24Maps->contains($map)) {
            $this->beds24Maps->add($map);
            $map->setPmsUnidad($this);
        }
        return $this;
    }

    public function removeBeds24Map(PmsUnidadBeds24Map $map): self
    {
        if ($this->beds24Maps->removeElement($map)) {
            if ($map->getPmsUnidad() === $this) {
                $map->setPmsUnidad(null);
            }
        }
        return $this;
    }

    public function getTarifaQueues(): Collection { return $this->tarifaQueues; }

    public function addTarifaQueue(PmsRatesPushQueue $queue): self
    {
        if (!$this->tarifaQueues->contains($queue)) {
            $this->tarifaQueues->add($queue);
            $queue->setUnidad($this);
        }
        return $this;
    }

    public function removeTarifaQueue(PmsRatesPushQueue $queue): self
    {
        if ($this->tarifaQueues->removeElement($queue)) {
            if ($queue->getUnidad() === $this) {
                $queue->setUnidad(null);
            }
        }
        return $this;
    }

    public function getBookingsPullQueues(): Collection { return $this->bookingsPullQueues; }

    public function addBookingsPullQueue(PmsBookingsPullQueue $job): self
    {
        if (!$this->bookingsPullQueues->contains($job)) {
            $this->bookingsPullQueues->add($job);
            $job->addUnidad($this);
        }
        return $this;
    }

    public function removeBookingsPullQueue(PmsBookingsPullQueue $job): self
    {
        if ($this->bookingsPullQueues->removeElement($job)) {
            $job->removeUnidad($this);
        }
        return $this;
    }

    public function __toString(): string
    {
        return $this->nombre ?? ('Unidad UUID ' . ($this->getId() ? $this->getId()->toBase32() : 'Nueva'));
    }

    public function getBeds24MapPrincipal(): ?PmsUnidadBeds24Map
    {
        foreach ($this->beds24Maps as $map) {
            if ($map->isEsPrincipal()) {
                return $map;
            }
        }
        return $this->beds24Maps->first() ?: null;
    }

    public function getGuia(): ?PmsGuia
    {
        return $this->guia;
    }

    public function setGuia(?PmsGuia $guia): self
    {
        $this->guia = $guia;

        if ($guia && $guia->getUnidad() !== $this) {
            $guia->setUnidad($this);
        }

        return $this;
    }

    #[Groups(['pax_reserva:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }
}