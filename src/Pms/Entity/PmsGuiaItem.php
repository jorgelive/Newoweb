<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Pms\Enum\PmsGuiaVisibilidad;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
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
    public const TIPO_AVISO = 'alert';

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaSeccionHasItem::class, cascade: ['persist', 'remove'])]
    private Collection $itemHasSecciones;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'El nombre interno es obligatorio')]
    private ?string $nombreInterno = null;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::TIPO_TARJETA, self::TIPO_ALBUM, self::TIPO_AVISO])]
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    private ?string $tipo = self::TIPO_TARJETA;

    /**
     * Quién puede ver este ítem. El default es PRIVADO a propósito: es el
     * comportamiento conservador para todo el contenido que existía antes de
     * este campo (la migración lo aplica en bloque). Sacar algo al escaparate
     * es una decisión editorial explícita, nunca un efecto secundario.
     *
     * Vive en el ítem y NO en la sección: PmsGuiaSeccion deriva su visibilidad
     * de los ítems que le quedan tras filtrar (ver PmsGuiaArbolFiltro). Con el
     * flag en los dos sitios habría dos campos capaces de contradecirse y
     * secciones vacías en el catálogo.
     */
    #[ORM\Column(type: 'string', length: 20, enumType: PmsGuiaVisibilidad::class, options: ['default' => 'privado'])]
    #[Assert\NotNull]
    private PmsGuiaVisibilidad $visibilidad = PmsGuiaVisibilidad::Privado;

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull(message: 'Debe ingresar al menos el título en español')]
    #[Assert\Count(min: 1, minMessage: 'Debe ingresar al menos un título')]
    private array $titulo = [];

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    private ?array $descripcion = [];

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'El icono no puede exceder los 50 caracteres')]
    private ?string $icono = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    private ?array $labelBoton = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = [];

    #[ORM\OneToMany(mappedBy: 'item', targetEntity: PmsGuiaItemGaleria::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    #[Assert\Valid]
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    private Collection $galeria;

    // ══════════════════════════════════════════════════════════════════════
    // PROPIEDADES VIRTUALES DE LA VISTA PÚBLICA (no persistidas)
    // Las llena PmsGuiaArbolFiltro; la entity no calcula ni consulta nada.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Título y cuerpo con los placeholders `{{ door_code }}` YA resueltos, en
     * todos los idiomas. Antes esa sustitución la hacía el navegador
     * (RichContentEngine.interpolateString), lo que obligaba a mandarle al
     * cliente el diccionario entero de valores sensibles para que eligiera
     * cuál pintar. Ahora el valor real solo sale del servidor si el acceso lo
     * permite; si no, en su lugar viaja el mensaje de bloqueo traducido.
     *
     * Se conserva la forma i18n `[{language, content}]` en vez de resolver el
     * idioma aquí a propósito: el selector de idioma de la guía cambia el
     * texto en caliente y no debe disparar una petición nueva.
     *
     * @var array<int, array{language: string, content: string}>
     */
    private array $tituloParaCliente = [];

    /** @var array<int, array{language: string, content: string}> */
    private array $contenidoParaCliente = [];

    /**
     * Momento en que este ítem deja de estar bloqueado, o null si ya es
     * visible. Solo se rellena en ítems `Llegada` que aún no han abierto: la
     * UI lo usa para pintar el candado con fecha en vez de tener que deducir
     * el estado cruzando flags.
     */
    private ?\DateTimeImmutable $bloqueadoHasta = null;

    public function __construct()
    {
        $this->galeria = new ArrayCollection();
        $this->itemHasSecciones = new ArrayCollection();
        $this->id = Uuid::v7();
        $this->titulo = [];
        $this->descripcion = [];
        $this->labelBoton = [];
        $this->tipo = self::TIPO_TARJETA;
        $this->nombreInterno = '';
        $this->metadata = [];
    }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getUrlBoton(): ?string { return $this->metadata['urlBoton'] ?? null; }
    public function setUrlBoton(?string $val): self {
        if ($this->metadata === null) $this->metadata = [];
        if (empty($val)) unset($this->metadata['urlBoton']);
        else $this->metadata['urlBoton'] = $val;
        return $this;
    }

    public function getNombreInterno(): ?string { return $this->nombreInterno; }
    public function setNombreInterno(string $nombreInterno): self { $this->nombreInterno = $nombreInterno; return $this; }

    public function getTipo(): string { return $this->tipo ?? self::TIPO_TARJETA; }
    public function setTipo(?string $tipo): self { $this->tipo = $tipo; return $this; }

    public function getVisibilidad(): PmsGuiaVisibilidad { return $this->visibilidad; }

    public function setVisibilidad(PmsGuiaVisibilidad|string|null $visibilidad): self
    {
        $this->visibilidad = is_string($visibilidad)
            ? (PmsGuiaVisibilidad::tryFrom($visibilidad) ?? PmsGuiaVisibilidad::Privado)
            : ($visibilidad ?? PmsGuiaVisibilidad::Privado);

        return $this;
    }

    /**
     * Expuesto al cliente para que la UI pueda pintar el candado sin recalcular
     * la regla: si el ítem llega bloqueado, ya viene con `bloqueadoHasta`.
     */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('visibilidad')]
    public function getVisibilidadApi(): string { return $this->visibilidad->value; }

    /* ======================================================
     * VISTA PÚBLICA (pax) — las llena PmsGuiaArbolFiltro
     * ====================================================== */

    public function setTituloParaCliente(array $titulo): self
    {
        $this->tituloParaCliente = $titulo;
        return $this;
    }

    /** @return array<int, array{language: string, content: string}> */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('titulo')]
    public function getTituloParaCliente(): array { return $this->tituloParaCliente; }

    public function setContenidoParaCliente(array $contenido): self
    {
        $this->contenidoParaCliente = $contenido;
        return $this;
    }

    /** @return array<int, array{language: string, content: string}> */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('descripcion')]
    public function getContenidoParaCliente(): array { return $this->contenidoParaCliente; }

    public function setBloqueadoHasta(?\DateTimeImmutable $bloqueadoHasta): self
    {
        $this->bloqueadoHasta = $bloqueadoHasta;
        return $this;
    }

    #[Groups(['pax_guia:read'])]
    public function getBloqueadoHasta(): ?\DateTimeImmutable { return $this->bloqueadoHasta; }

    /** Atajo para la UI: pinta el candado sin comparar fechas en el navegador. */
    #[Groups(['pax_guia:read'])]
    public function isBloqueado(): bool { return null !== $this->bloqueadoHasta; }

    // Contenido CRUDO, con los `{{ placeholders }}` sin resolver. No se
    // serializa nunca: solo lo lee PmsGuiaArbolFiltro para pasárselo al
    // interpolador. Lo que ve el cliente es getTituloParaCliente() /
    // getContenidoParaCliente(). Si algún día alguien le pone un grupo a estos
    // dos, el huésped verá `{{ door_code }}` literal en pantalla.
    public function getTitulo(): array { return MaestroIdioma::ordenarParaFormulario($this->titulo); }
    public function setTitulo(array $titulo): self { $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this; }

    public function getDescripcion(): ?array { return MaestroIdioma::ordenarParaFormulario($this->descripcion ?? []); }
    public function setDescripcion(?array $descripcion): self { $this->descripcion = MaestroIdioma::normalizarParaDB($descripcion ?? []); return $this; }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getIcono(): ?string
    {
        return $this->icono;
    }

    public function setIcono(?string $icono): self
    {
        $this->icono = $icono;
        return $this;
    }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getLabelBoton(): ?array { return MaestroIdioma::ordenarParaFormulario($this->labelBoton ?? []); }
    public function setLabelBoton(?array $labelBoton): self { $this->labelBoton = MaestroIdioma::normalizarParaDB($labelBoton ?? []); return $this; }

    public function getMetadata(): array { return $this->metadata ?? []; }
    public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }

    public function getGaleria(): Collection { return $this->galeria; }
    public function addGaleria(PmsGuiaItemGaleria $galeria): self { if (!$this->galeria->contains($galeria)) { $this->galeria->add($galeria); $galeria->setItem($this); } return $this; }
    public function removeGaleria(PmsGuiaItemGaleria $galeria): self { if ($this->galeria->removeElement($galeria)) { if ($galeria->getItem() === $this) { $galeria->setItem(null); } } return $this; }

    public function getItemHasSecciones(): Collection { return $this->itemHasSecciones; }

    public function __toString(): string { return $this->nombreInterno ?: ($this->titulo['es'] ?? 'Ítem sin nombre'); }

    public function getVirtualGaleria(): string { return ''; }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $espanolEncontrado = false;
        if (!empty($this->titulo)) {
            foreach ($this->titulo as $item) {
                if (($item['language'] ?? '') === 'es' && !empty(trim($item['content'] ?? ''))) {
                    $espanolEncontrado = true;
                    break;
                }
            }
        }
        if (!$espanolEncontrado) $context->buildViolation('El título en español es obligatorio.')->atPath('titulo')->addViolation();

        $hasUrl = !empty($this->getUrlBoton());
        $hasLabel = false;
        if (!empty($this->labelBoton)) {
            foreach ($this->labelBoton as $item) {
                if (!empty(trim($item['content'] ?? ''))) {
                    $hasLabel = true;
                    break;
                }
            }
        }
        if ($hasUrl && !$hasLabel) $context->buildViolation('Si pones una URL, el botón debe tener texto.')->atPath('labelBoton')->addViolation();
    }

    public function getVirtualTitulo(): string { return ''; }
    public function getVirtualDescripcion(): string { return ''; }

    public function getVirtualLabelBoton(): string { return ''; }

}