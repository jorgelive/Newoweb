<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use App\Pms\Enum\PmsEstablecimientoMediaTipo;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Los medios que son del EDIFICIO: las dos cajas fuertes del pasaje.
 *
 * Gemela de {@see PmsUnidadMedia} y a propósito: mismo Vich, mismo namer, misma pareja de
 * listeners, mismo «uno por tipo». Lo único que cambia es de qué cuelga y qué tipos admite.
 *
 * ── Por qué una entidad y no cuatro campos en `PmsEstablecimiento` ──────────
 * Porque ya se probó con uno y salió mal. `video_caja_fuerte_url` era un campo suelto de esa
 * entidad; sobrevivió **un día entero vacío** porque nadie lo puso en el panel, así que la guía
 * pedía `{{ video_caja_fuerte }}`, resolvía a nada, y el vídeo seguía copiado a mano dentro de un
 * ítem en los siete idiomas. Cuatro campos habrían sido cuatro veces esa historia.
 *
 * Con la entidad, un medio nuevo es un caso del enum: sin migración y sin tocar el panel.
 *
 * ── Lo del dinero no sale en la guía ────────────────────────────────────────
 * {@see PmsEstablecimientoMediaTipo::visibilidad()} devuelve `null` para las dos de la caja del
 * dinero, y {@see \App\Pms\Guia\PmsGuiaContexto::construir()} —única puerta a la guía— no las
 * carga. El huésped no puede pedirlas: se las entrega un operador con `enviar_plantilla`, que
 * exige `ROLE_MENSAJES_WRITE`.
 *
 * Ver `docs/PmsGuiaHuesped.md` §3.c.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_establecimiento_media')]
// La restricción la impone la BASE; esto la explica antes, en el formulario. Sin ella, subir un
// segundo vídeo del mismo tipo devuelve un error crudo de clave duplicada: correcto e ilegible.
#[UniqueEntity(
    fields: ['establecimiento', 'tipo'],
    message: 'Este establecimiento ya tiene ese medio. Hay UNO de cada tipo: edita el que existe o bórralo antes de subir otro.'
)]
#[ORM\UniqueConstraint(name: 'uniq_establecimiento_tipo', columns: ['establecimiento_id', 'tipo'])]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsEstablecimientoMedia
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;

    #[ORM\ManyToOne(targetEntity: PmsEstablecimiento::class, inversedBy: 'medios')]
    #[ORM\JoinColumn(name: 'establecimiento_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsEstablecimiento $establecimiento = null;

    #[ORM\Column(type: 'string', length: 30, enumType: PmsEstablecimientoMediaTipo::class)]
    private PmsEstablecimientoMediaTipo $tipo = PmsEstablecimientoMediaTipo::FOTO_CAJA_LLAVES;

    #[Vich\UploadableField(mapping: 'establecimiento_images', fileNameProperty: 'imageName')]
    #[Assert\File(
        maxSize: '5M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Formato no válido. Use JPG, PNG o WEBP.'
    )]
    private ?File $imageFile = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $imageUpdatedAt = null;

    /**
     * La URL, para los tipos que no son archivo.
     *
     * ⚠️ Se valida que sea de YouTube y no «una URL cualquiera»: el front la incrusta como vídeo,
     * así que un enlace a otra cosa no fallaría — pintaría un reproductor vacío delante del
     * huésped.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Url(message: 'Tiene que ser una URL completa, empezando por https://')]
    #[Assert\Regex(
        pattern: '#^https://(www\.)?(youtube\.com/|youtu\.be/)#i',
        message: 'Sólo se aceptan URLs de YouTube: es lo que el front sabe incrustar.'
    )]
    private ?string $url = null;

    /** Para el día que un tipo admita varios. Hoy la restricción única lo deja en uno. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $orden = 0;

    public function __construct()
    {
        // `IdTrait` declara la columna pero no genera el valor: sin esto, `persist()` revienta con
        // «missing an assigned ID».
        $this->id = Uuid::v7();
    }

    /** PROPIEDAD VIRTUAL: la rellena el listener de assets para la vista previa del panel. */
    private ?string $imageUrl = null;

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /** El namer de los archivos va por este token: sin él, la subida no sabe cómo llamarlos. */
    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    public function getEstablecimiento(): ?PmsEstablecimiento
    {
        return $this->establecimiento;
    }

    public function setEstablecimiento(?PmsEstablecimiento $establecimiento): self
    {
        $this->establecimiento = $establecimiento;

        return $this;
    }

    public function getTipo(): PmsEstablecimientoMediaTipo
    {
        return $this->tipo;
    }

    public function setTipo(PmsEstablecimientoMediaTipo $tipo): self
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageFile(?File $imageFile = null): self
    {
        $this->imageFile = $imageFile;

        // Vich sólo persiste el archivo si algo más de la entidad cambia: sin esto, sustituir la
        // imagen sin tocar ningún otro campo no dispara el `preUpdate` y el archivo se descarta.
        if ($imageFile !== null) {
            $this->imageUpdatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setImageName(?string $imageName): self
    {
        $this->imageName = $imageName;

        return $this;
    }

    public function getImageUpdatedAt(): ?DateTimeInterface
    {
        return $this->imageUpdatedAt;
    }

    public function setImageUpdatedAt(?DateTimeInterface $imageUpdatedAt): self
    {
        $this->imageUpdatedAt = $imageUpdatedAt;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;

        return $this;
    }

    /**
     * Lo que hay que servir, venga de un archivo o de una URL.
     *
     * Se lo pregunta al TIPO y no a cuál de las dos columnas está rellena: si alguien sube un
     * archivo a un tipo de vídeo, lo que manda es lo que ese tipo promete.
     */
    public function getValor(): ?string
    {
        if (!$this->tipo->esArchivo()) {
            return $this->url;
        }

        // La URL pública la rellena `PmsEstablecimientoMediaAssetListener` al cargar: la ruta es
        // configuración y no puede vivir dentro de la entidad. Sin listener no hay URL que dar, y
        // devolver el nombre del archivo pintaría una imagen rota.
        return $this->imageUrl !== null && $this->imageUrl !== ''
            ? $this->imageUrl
            : null;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s — %s',
            $this->establecimiento?->getNombreComercial() ?? '?',
            $this->tipo->etiqueta()
        );
    }
}
