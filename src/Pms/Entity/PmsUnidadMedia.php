<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Panel\Entity\Trait\MediaTrait;
use App\Pms\Enum\PmsUnidadMediaTipo;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * Los medios que son de la CASITA: su croquis, la foto de su puerta, el vídeo de su ingreso.
 *
 * ── Por qué no viven en la galería de un ítem de guía ───────────────────────
 * Porque son un **dato**, no maquetación, y §3.b de `docs/PmsGuiaHuesped.md` ya documenta lo que
 * cuesta confundir las dos cosas: los teléfonos de atención vivían dentro del texto de un ítem, un
 * ítem sólo llega al modelo si el índice de temas lo selecciona por sus términos, una huésped
 * escribió «acabamos de llegar» —que no casa con ninguno—, y el modelo se inventó que alguien iría
 * a recibirla. **Estuvo una hora en la puerta.**
 *
 * La regla que salió de ahí: *si un dato tiene que salir sí o sí en un momento concreto, va por el
 * camino determinista*. Una foto de la puerta hace falta **cuando alguien no la encuentra**, no
 * cuando pregunta por el álbum. Colgada de la unidad se resuelve con
 * `$unidad->medio(PmsUnidadMediaTipo::CROQUIS)`; colgada del ítem habría que recorrer casita →
 * guía → sección → ítem correcto → imagen marcada, y depende de que ese ítem exista.
 *
 * Y es de la casa, no del texto: así la puede usar cualquier ítem, la skill, y lo que venga.
 *
 * ── La línea con la galería, que hay que mantener nítida ────────────────────
 * Quedan dos almacenes de imagen y sólo es sano si la frontera se respeta:
 *
 * | aquí | en {@see PmsGuiaItemGaleria} |
 * |---|---|
 * | medios que son un DATO: la puerta, el ingreso, mañana el estacionamiento | medios que son MAQUETACIÓN: el álbum, la decoración |
 *
 * ⚠️ **Y no se duplican.** Cuando el croquis de una casita entra aquí, el ítem «Puerta del
 * Departamento» deja de llevar su copia y pasa a escribir `{{ croquis }}`. Un dato en dos sitios
 * es un dato que un día se cambia en uno solo.
 *
 * Ver `docs/PmsGuiaHuesped.md` §3.c.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pms_unidad_media')]
// La restricción de arriba la impone la BASE; ésta la explica ANTES, en el formulario. Sin ella,
// intentar subir un segundo croquis a la misma casita devuelve un error crudo de clave duplicada:
// correcto pero ilegible, y quien lo ve no sabe que lo que tiene que hacer es reemplazar el que ya
// hay.
#[UniqueEntity(
    fields: ['unidad', 'tipo'],
    message: 'Esta casita ya tiene ese medio. Cada casita tiene UNO de cada tipo: edita el que ya existe o bórralo antes de subir otro.'
)]
// **Un tipo, un medio por casita**, y la restricción es deliberada: el marcador `{{ foto_puerta }}`
// tiene que resolver a UNA imagen. Sin ella decidiría `orden`, y entonces subir una foto con orden
// 0 cambiaría en silencio lo que ve todo huésped que abra su guía — nadie asocia «subí una foto»
// con «cambié la que se manda».
//
// Si una segunda imagen dice algo DISTINTO —el pasaje, además de la puerta—, eso es otro tipo, y
// para eso el enum está abierto. Si es la misma cosa repetida, sobra y su sitio es la galería.
#[ORM\UniqueConstraint(name: 'uniq_unidad_tipo', columns: ['unidad_id', 'tipo'])]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class PmsUnidadMedia
{
    use IdTrait;
    use TimestampTrait;
    use MediaTrait;

    #[ORM\ManyToOne(targetEntity: PmsUnidad::class, inversedBy: 'medios')]
    #[ORM\JoinColumn(name: 'unidad_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PmsUnidad $unidad = null;

    #[ORM\Column(type: 'string', length: 30, enumType: PmsUnidadMediaTipo::class)]
    private PmsUnidadMediaTipo $tipo = PmsUnidadMediaTipo::CROQUIS;

    #[Vich\UploadableField(mapping: 'unidad_images', fileNameProperty: 'imageName')]
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
     * ⚠️ Se valida que sea de YouTube y no «una URL cualquiera»: el front la incrusta como vídeo
     * ({@see \App\Pms\Guia\PmsGuiaInterpolador} la degrada a `{{ video: … }}`), así que un enlace
     * a otra cosa no fallaría — se pintaría un reproductor vacío delante del huésped.
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
        // El id se asigna aquí, como en el resto de entidades del módulo: `IdTrait` declara la
        // columna pero no genera el valor, y sin esto `persist()` revienta con «missing an
        // assigned ID».
        $this->id = Uuid::v7();
    }

    /** PROPIEDAD VIRTUAL: la rellena el listener de Liip para la vista previa del panel. */
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

    public function getUnidad(): ?PmsUnidad
    {
        return $this->unidad;
    }

    public function setUnidad(?PmsUnidad $unidad): self
    {
        $this->unidad = $unidad;

        return $this;
    }

    public function getTipo(): PmsUnidadMediaTipo
    {
        return $this->tipo;
    }

    public function setTipo(PmsUnidadMediaTipo $tipo): self
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
     * archivo a un `VIDEO_INGRESO`, lo que manda es lo que ese tipo promete.
     */
    public function getValor(): ?string
    {
        if (!$this->tipo->esArchivo()) {
            return $this->url;
        }

        // La URL pública la rellena `PmsUnidadMediaAssetListener` al cargar: la ruta es
        // configuración y no puede vivir dentro de la entidad. Sin listener —una entidad recién
        // creada en memoria— no hay URL que dar, y devolver el nombre del archivo pintaría una
        // imagen rota en la guía.
        return $this->imageUrl !== null && $this->imageUrl !== ''
            ? $this->imageUrl
            : null;
    }

    public function __toString(): string
    {
        return sprintf('%s — %s', $this->unidad?->getNombre() ?? '?', $this->tipo->etiqueta());
    }
}
