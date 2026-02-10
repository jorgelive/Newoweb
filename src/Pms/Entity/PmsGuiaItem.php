<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\AutoTranslateControlTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia_item')]
#[ORM\HasLifecycleCallbacks]
class PmsGuiaItem
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    public const TIPO_TARJETA = 'card';
    public const TIPO_ALBUM = 'album';
    public const TIPO_VIDEO = 'video';
    public const TIPO_LOCATION = 'location';
    public const TIPO_WIFI = 'wifi';
    public const TIPO_CONTACTO = 'contact';
    public const TIPO_SERVICIO = 'service';

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaSeccionHasItem::class, cascade: ['persist', 'remove'])]
    private Collection $itemHasSecciones;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'El nombre interno es obligatorio')]
    private ?string $nombreInterno = null;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank]
    // ✅ Corregido: Quitamos TIPO_MAPA y agregamos TIPO_LOCATION
    #[Assert\Choice(choices: [self::TIPO_TARJETA, self::TIPO_ALBUM, self::TIPO_VIDEO, self::TIPO_LOCATION, self::TIPO_WIFI, self::TIPO_CONTACTO, self::TIPO_SERVICIO])]
    private ?string $tipo = self::TIPO_TARJETA;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'Debe ingresar al menos el título en español')]
    #[Assert\Count(min: 1, minMessage: 'Debe ingresar al menos un título')]
    private array $titulo = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    private ?array $descripcion = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $labelBoton = [];

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaItemGaleria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    #[Assert\Valid]
    private Collection $galeria;

    public function __construct()
    {
        $this->galeria = new ArrayCollection();
        $this->itemHasSecciones = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->titulo = $this->descripcion = $this->labelBoton = [];
        $this->tipo = self::TIPO_TARJETA;
        $this->nombreInterno = '';
        $this->metadata = []; // Inicializamos vacío
    }

    // --- API GROUPS EN GETTERS ---

    #[Groups(['guia:read'])]
    public function getTipo(): string { return $this->tipo ?? self::TIPO_TARJETA; }

    #[Groups(['guia:read'])]
    public function getTitulo(): array { return MaestroIdioma::ordenarParaFormulario($this->titulo); }

    #[Groups(['guia:read'])]
    public function getDescripcion(): ?array { return MaestroIdioma::ordenarParaFormulario($this->descripcion ?? []); }

    #[Groups(['guia:read'])]
    public function getLabelBoton(): ?array { return MaestroIdioma::ordenarParaFormulario($this->labelBoton ?? []); }

    #[Groups(['guia:read'])]
    public function getGaleria(): Collection { return $this->galeria; }

    // =========================================================================
    // 🎥 PROPIEDADES VIRTUALES (Mapeadas a Metadata)
    // =========================================================================

    #[Groups(['guia:read'])]
    public function getMetadata(): array
    {
        return $this->metadata ?? [];
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    // --- 📍 UBICACIÓN (LOCATION - NUEVO) ---
    // Estos métodos son OBLIGATORIOS para que el CRUD funcione

    public function getLocationAddress(): ?string
    {
        return $this->getMetadata()['locationAddress'] ?? null;
    }

    public function setLocationAddress(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['locationAddress'] = $val;
        return $this;
    }

    public function getLocationLat(): ?string
    {
        return $this->getMetadata()['locationLat'] ?? null;
    }

    public function setLocationLat(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['locationLat'] = $val;
        return $this;
    }

    public function getLocationLng(): ?string
    {
        return $this->getMetadata()['locationLng'] ?? null;
    }

    public function setLocationLng(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['locationLng'] = $val;
        return $this;
    }

    public function getLocationLink(): ?string
    {
        return $this->getMetadata()['locationLink'] ?? null;
    }

    public function setLocationLink(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['locationLink'] = $val;
        return $this;
    }

    // =========================================================================
    // 🎥 VIDEOS (MULTIPLES)
    // =========================================================================

    public function getVideos(): array
    {
        // Devuelve el array de videos o un array vacío si no existe
        return $this->getMetadata()['videos'] ?? [];
    }

    public function setVideos(?array $val): self
    {
        // Limpiamos índices numéricos feos y reindexamos el array
        // Esto evita que el JSON se guarde como objetos {"0":..., "1":...}
        $val = $val ? array_values($val) : [];

        if ($this->metadata === null) { $this->metadata = []; }

        $this->metadata['videos'] = $val;

        return $this;
    }

    // --- 📶 WIFI ---

    public function getWifiSsid(): ?string
    {
        return $this->getMetadata()['wifiSsid'] ?? null;
    }

    public function setWifiSsid(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['wifiSsid'] = $val;
        return $this;
    }

    public function getWifiPass(): ?string
    {
        return $this->getMetadata()['wifiPass'] ?? null;
    }

    public function setWifiPass(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['wifiPass'] = $val;
        return $this;
    }

    // --- 📞 CONTACTO ---

    public function getContactoWhatsapp(): ?string
    {
        return $this->getMetadata()['whatsapp'] ?? null;
    }

    public function setContactoWhatsapp(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['whatsapp'] = $val;
        return $this;
    }

    public function getContactoEmail(): ?string
    {
        return $this->getMetadata()['email'] ?? null;
    }

    public function setContactoEmail(?string $val): self
    {
        if ($this->metadata === null) { $this->metadata = []; }
        $this->metadata['email'] = $val;
        return $this;
    }

    // --- SETTERS ESTÁNDAR ---

    public function getNombreInterno(): ?string { return $this->nombreInterno; }
    public function setNombreInterno(string $nombreInterno): self { $this->nombreInterno = $nombreInterno; return $this; }

    public function getItemHasSecciones(): Collection { return $this->itemHasSecciones; }

    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    public function setTitulo(array $titulo): self { $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this; }
    public function setDescripcion(?array $descripcion): self { $this->descripcion = MaestroIdioma::normalizarParaDB($descripcion ?? []); return $this; }
    public function setLabelBoton(?array $labelBoton): self { $this->labelBoton = MaestroIdioma::normalizarParaDB($labelBoton ?? []); return $this; }

    public function addGaleria(PmsGuiaItemGaleria $galeria): self { if (!$this->galeria->contains($galeria)) { $this->galeria->add($galeria); $galeria->setItem($this); } return $this; }
    public function removeGaleria(PmsGuiaItemGaleria $galeria): self { if ($this->galeria->removeElement($galeria)) { if ($galeria->getItem() === $this) { $galeria->setItem(null); } } return $this; }

    public function __toString(): string { return $this->nombreInterno ?: ($this->titulo['es'] ?? 'Ítem sin nombre'); }

    // --- VALIDACIONES ---

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $espanolEncontrado = false;

        // Verificamos que no esté vacío el campo principal
        if (!empty($this->titulo) && is_iterable($this->titulo)) {

            foreach ($this->titulo as $item) {
                // 1. Accedemos como Array Asociativo: $item['language']
                // Usamos operador null coalescing (??) por seguridad
                $lang = $item['language'] ?? null;
                $content = $item['content'] ?? null;

                // 2. Validamos si es español y tiene contenido real
                if ($lang === 'es' && !empty(trim($content))) {
                    $espanolEncontrado = true;
                    break;
                }
            }
        }

        if (!$espanolEncontrado) {
            $context->buildViolation('El título en español (es) es obligatorio.')
                ->atPath('titulo')
                ->addViolation();
        }
    }
}