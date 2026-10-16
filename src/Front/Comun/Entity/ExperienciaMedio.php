<?php

declare(strict_types=1);

namespace App\Front\Comun\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Front\Comun\Dto\VideoExterno;
use App\Front\Comun\Enum\ExperienciaMedioTipoEnum;
use App\Front\Comun\ApiPlatform\State\ExperienciaMedioProcessor;
use App\Panel\Entity\Trait\MediaTrait;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Una foto o un vídeo de un `ExperienciaAlbum`.
 *
 * - **Foto**: subida por multipart (campo `imagen`, `ExperienciaMedioProcessor`), en una carpeta
 *   pública (`front.path.experiencias`); `VichWebpConversionListener` la pasa a WebP y la web la
 *   sirve por Liip (`travel_galeria` en la rejilla, `travel_cliente` al abrirla).
 * - **Vídeo**: sólo el enlace de YouTube o Vimeo, validado por `VideoExterno::desdeUrl()`.
 *
 * Se lee siempre a través de su álbum (grupo `experiencia:read`); por eso no tiene `Get` propio.
 * Mismo patrón que `TravelOrganizacionImagen`, con pie traducido.
 */
#[ApiResource(
    shortName: 'ExperienciaMedio',
    operations: [
        // Alta por multipart (foto) o JSON (vídeo). En multipart todo llega como texto, de ahí
        // `disable_type_enforcement`: `orden` viaja como "3" y `esPortada` como "1".
        new Post(
            inputFormats: [
                'jsonld' => ['application/ld+json'],
                'json' => ['application/json'],
                'multipart' => ['multipart/form-data'],
            ],
            normalizationContext: ['groups' => ['experiencia:read']],
            denormalizationContext: ['groups' => ['experiencia_medio:write'], 'disable_type_enforcement' => true],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            processor: ExperienciaMedioProcessor::class,
        ),
        // Reordenar, cambiar el pie o la portada no reenvían el binario.
        //
        // ⚠️ **Grupo propio, no el del alta.** Con el de escritura entero, un PATCH podía cambiar
        // `tipo` de `video` a `foto` —dejando una foto sin archivo, porque el processor sólo corre
        // en el Post— o mudar el medio a OTRO álbum, saltándose la autorización del suyo. Lo que
        // se retoca después de subir es sólo la colocación y el pie.
        new Patch(
            normalizationContext: ['groups' => ['experiencia:read']],
            denormalizationContext: ['groups' => ['experiencia_medio:patch']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
        ),
        new Delete(
            normalizationContext: ['groups' => ['experiencia:read']],
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
        ),
    ],
    routePrefix: '/front',
)]
#[ORM\Entity]
#[ORM\Table(name: 'front_experiencia_medio')]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class ExperienciaMedio
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;
    use AutoTranslateControlTrait;

    #[Groups(['experiencia:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    // Sin `NotNull` la columna lo rechazaba igual, pero en MySQL: un alta sin álbum daba un 500
    // en vez de un 422 con el motivo. El mismo caso que `CotizacionPago::$file`.
    #[Groups(['experiencia_medio:write'])]
    #[Assert\NotNull(message: 'El medio tiene que ser de un álbum.')]
    #[ORM\ManyToOne(targetEntity: ExperienciaAlbum::class, inversedBy: 'medios')]
    #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ExperienciaAlbum $album = null;

    #[Groups(['experiencia:read', 'experiencia_medio:write'])]
    #[ORM\Column(type: 'string', length: 10, enumType: ExperienciaMedioTipoEnum::class)]
    private ExperienciaMedioTipoEnum $tipo = ExperienciaMedioTipoEnum::FOTO;

    #[Groups(['experiencia:read', 'experiencia_medio:write', 'experiencia_medio:patch'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    #[Groups(['experiencia:read', 'experiencia_medio:write', 'experiencia_medio:patch'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $esPortada = false;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['experiencia:read', 'experiencia_medio:write', 'experiencia_medio:patch'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $pie = [];

    /** Sólo en los vídeos: el enlace de YouTube o Vimeo tal como lo pegó el operador. */
    #[Groups(['experiencia:read', 'experiencia_medio:write'])]
    #[Assert\Length(max: 255)]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $videoUrl = null;

    /* ── Foto (VichUploader) ─────────────────────────────────────────────── */

    #[Vich\UploadableField(mapping: 'front_experiencias', fileNameProperty: 'imageName', size: 'imageSize')]
    private ?File $imageFile = null;

    #[Groups(['experiencia:read'])]
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imageSize = null;

    /** Virtual: la URL pública, la pone `ExperienciaMedioAssetListener` al cargar. */
    #[Groups(['experiencia:read'])]
    private ?string $imageUrl = null;

    public function __construct()
    {
        $this->initializeId();
    }

    #[ORM\PrePersist]
    public function setupMediaToken(): void
    {
        $this->initializeToken();
    }

    /**
     * Un vídeo trae un enlace que se sepa reproducir.
     *
     * ⚠️ «Una foto trae su archivo» NO va aquí: API Platform valida ANTES del procesador, que es
     * quien recoge el archivo del multipart, así que aquí la foto aún no ha llegado y la regla
     * rechazaba todas las subidas. La comprueba `ExperienciaMedioProcessor`.
     */
    #[Assert\Callback]
    public function validar(ExecutionContextInterface $context): void
    {
        if ($this->tipo === ExperienciaMedioTipoEnum::VIDEO && VideoExterno::desdeUrl($this->videoUrl) === null) {
            $context->buildViolation('El enlace tiene que ser de un vídeo de YouTube o de Vimeo.')->atPath('videoUrl')->addViolation();
        }
    }

    public function esFoto(): bool
    {
        return $this->tipo === ExperienciaMedioTipoEnum::FOTO;
    }

    /** El vídeo ya leído; `null` en las fotos o con un enlace que no es de YouTube ni Vimeo. */
    public function video(): ?VideoExterno
    {
        return $this->tipo === ExperienciaMedioTipoEnum::VIDEO ? VideoExterno::desdeUrl($this->videoUrl) : null;
    }

    /** La ruta guardada, para Liip: `/carga/front/experiencias/xxx.webp`. */
    public function rutaDeLaFoto(): ?string
    {
        return $this->imageUrl;
    }

    public function getAlbum(): ?ExperienciaAlbum { return $this->album; }
    public function setAlbum(?ExperienciaAlbum $album): self { $this->album = $album; return $this; }

    public function getTipo(): ExperienciaMedioTipoEnum { return $this->tipo; }
    public function setTipo(ExperienciaMedioTipoEnum $tipo): self { $this->tipo = $tipo; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function isEsPortada(): bool { return $this->esPortada; }
    public function setEsPortada(bool $esPortada): self { $this->esPortada = $esPortada; return $this; }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getPie(): array { return $this->pie; }
    /** @param list<array{language?: string, content?: string|null}> $pie */
    public function setPie(array $pie): self { $this->pie = $pie; return $this; }

    public function getVideoUrl(): ?string { return $this->videoUrl; }
    public function setVideoUrl(?string $videoUrl): self { $this->videoUrl = $videoUrl !== null && trim($videoUrl) !== '' ? trim($videoUrl) : null; return $this; }

    public function getImageFile(): ?File { return $this->imageFile; }
    public function setImageFile(?File $imageFile = null): void
    {
        $this->imageFile = $imageFile;
        if ($imageFile !== null) {
            $this->updatedAt = new DateTimeImmutable();
        }
    }

    public function getImageName(): ?string { return $this->imageName; }
    public function setImageName(?string $imageName): self { $this->imageName = $imageName; return $this; }

    public function getImageSize(): ?int { return $this->imageSize; }
    public function setImageSize(?int $imageSize): self { $this->imageSize = $imageSize; return $this; }

    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $imageUrl): self { $this->imageUrl = $imageUrl; return $this; }
}
