<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Travel\Enum\PuntoModoEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ApiResource(
    shortName: 'Segmento',
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['segmento:read']], security: "is_granted('" . Roles::MAESTROS_SHOW . "')"),
        new Get(normalizationContext: ['groups' => ['segmento:item:read']], security: "is_granted('" . Roles::MAESTROS_SHOW . "')"),
        new Post(denormalizationContext: ['groups' => ['segmento:write']], securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')", securityPostDenormalizeMessage: 'No tienes permiso para crear segmentos.'),
        new Put(denormalizationContext: ['groups' => ['segmento:write']], security: "is_granted('" . Roles::MAESTROS_WRITE . "')", securityMessage: 'No tienes permiso para editar segmentos.'),
        new Delete(security: "is_granted('" . Roles::MAESTROS_DELETE . "')", securityMessage: 'No tienes permiso para eliminar segmentos.')
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_segmento')]
#[ORM\HasLifecycleCallbacks]
class TravelSegmento
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    /** @var Collection<int, TravelServicio> */
    #[ORM\ManyToMany(targetEntity: TravelServicio::class, inversedBy: 'segmentos')]
    #[ORM\JoinTable(name: 'travel_segmento_servicio_pool')]
    private Collection $servicios;

    // El CÓDIGO del segmento (`VIS-VALLE_VIP…-CHINCHERO`). Se separó de `nombreInterno` para que
    // éste pase a ser un nombre real, igual que en TravelItinerario. En EasyAdmin va primero.
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $slug = null;

    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreInterno = null;

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenido = [];

    // ═══════════════════════════════════════════════════════════════════════════
    // DÓNDE EMPIEZA Y DÓNDE TERMINA — lo que el proveedor pregunta primero
    // ═══════════════════════════════════════════════════════════════════════════
    //
    // Vive en el SEGMENTO y no en el componente por una razón que dieron los datos: hay 33
    // segmentos compartidos entre itinerarios, y lo son **precisamente porque significan el
    // mismo sitio** — «Retorno al centro de Cusco» aparece en cuatro plantillas y en las cuatro
    // deja al pasajero donde mismo. Guardarlo en el componente obligaría a repetirlo por cada
    // plantilla y a mantenerlo sincronizado a mano.
    //
    // De aquí sale el origen y el destino del servicio que ABARCA el día — el que lleva
    // `TravelSegmentoComponente::$horaServicioCompleto`, único por (plantilla, día): empieza en
    // el `inicioPunto` del primer segmento de esa plantilla+día y termina en el `finPunto` del
    // último. Es la misma clave que ya usa
    // {@see \App\Travel\EventListener\TravelSegmentoComponentePromocionUnicaListener}, y por eso
    // no hizo falta ni una tabla nueva ni una segunda forma de colgar componentes.
    //
    // ⚠️ Los OVERRIDES de una plantilla concreta —el itinerario que en vez de devolver al hotel
    // deja en la estación de Ollantaytambo— no necesitan nada de esto: son **otro segmento
    // final**, y el itinerario ya elige cuáles inyecta. Modelarlos como excepción sobre el
    // segmento compartido habría sido resolver dos veces algo que la relación ya resuelve.

    #[ORM\Column(name: 'inicio_modo', type: 'string', length: 20, enumType: PuntoModoEnum::class, options: ['default' => 'sin_definir'])]
    private PuntoModoEnum $inicioModo = PuntoModoEnum::SIN_DEFINIR;

    #[ORM\ManyToOne(targetEntity: TravelPunto::class)]
    #[ORM\JoinColumn(name: 'inicio_punto_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TravelPunto $inicioPunto = null;

    #[ORM\Column(name: 'fin_modo', type: 'string', length: 20, enumType: PuntoModoEnum::class, options: ['default' => 'sin_definir'])]
    private PuntoModoEnum $finModo = PuntoModoEnum::SIN_DEFINIR;

    #[ORM\ManyToOne(targetEntity: TravelPunto::class)]
    #[ORM\JoinColumn(name: 'fin_punto_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TravelPunto $finPunto = null;

    /** @var Collection<int, TravelNota> */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[ORM\ManyToMany(targetEntity: TravelNota::class, inversedBy: 'segmentos')]
    #[ORM\JoinTable(name: 'travel_segmento_notas_rel')]
    private Collection $notas;

    /** @var Collection<int, TravelSegmentoImagen> */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write'])]
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoImagen::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $imagenes;

    /** @var Collection<int, TravelSegmentoComponente> */
    #[Groups(['segmento:read', 'segmento:item:read', 'segmento:write', 'servicio:item:read'])]
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelSegmentoComponente::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $segmentoComponentes;

    /**
     * 🔍 SOLO LECTURA: lado inverso para saber en qué Itinerarios (plantillas) y en qué
     * día se está inyectando este segmento. El dueño real es TravelItinerarioSegmentoRel.
     */
    /** @var Collection<int, TravelItinerarioSegmentoRel> */
    #[ORM\OneToMany(mappedBy: 'segmento', targetEntity: TravelItinerarioSegmentoRel::class)]
    private Collection $itinerarioSegmentosInyectados;

    public function __construct()
    {
        $this->initializeId();
        $this->servicios = new ArrayCollection();
        $this->notas = new ArrayCollection();
        $this->imagenes = new ArrayCollection();
        $this->segmentoComponentes = new ArrayCollection();
        $this->itinerarioSegmentosInyectados = new ArrayCollection();
    }

    public function __clone()
    {
        $this->resetId();
        $this->resetTimestamps();

        if ($this->nombreInterno) {
            $this->nombreInterno = '(Clon) ' . $this->nombreInterno;
        }

        $serviciosOriginales = $this->servicios;
        $this->servicios = new ArrayCollection();
        foreach ($serviciosOriginales as $servicio) {
            $this->addServicio($servicio);
        }

        $notasOriginales = $this->notas;
        $this->notas = new ArrayCollection();
        foreach ($notasOriginales as $nota) {
            $this->addNota($nota);
        }

        $componentesOriginales = $this->segmentoComponentes;
        $this->segmentoComponentes = new ArrayCollection();
        foreach ($componentesOriginales as $compOriginal) {
            $clonComp = clone $compOriginal;
            $this->addSegmentoComponente($clonComp);
        }

    }

    public function __toString(): string
    {
        return $this->nombreInterno ?? 'Sin nombre';
    }

    #[Groups(['segmento:read', 'segmento:item:read', 'servicio:item:read', 'cotizacion:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /**
     * @return Collection<int, TravelServicio>
     */
    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    public function addServicio(TravelServicio $servicio): self
    {
        if (!$this->servicios->contains($servicio)) {
            $this->servicios->add($servicio);
        }
        return $this;
    }

    public function removeServicio(TravelServicio $servicio): self
    {
        $this->servicios->removeElement($servicio);
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getNombreInterno(): ?string
    {
        return $this->nombreInterno;
    }

    public function setNombreInterno(string $nombreInterno): self
    {
        $this->nombreInterno = $nombreInterno;
        return $this;
    }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTitulo(): array
    {
        return $this->titulo;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getContenido(): array
    {
        return $this->contenido;
    }

    /**
     * @param list<array{language?: string, content?: string|null}> $contenido
     */
    public function setContenido(array $contenido): self
    {
        $this->contenido = $contenido;
        return $this;
    }

    /**
     * @return Collection<int, TravelNota>
     */
    public function getNotas(): Collection
    {
        return $this->notas;
    }

    public function addNota(TravelNota $nota): self
    {
        if (!$this->notas->contains($nota)) {
            $this->notas->add($nota);
        }
        return $this;
    }

    public function removeNota(TravelNota $nota): self
    {
        $this->notas->removeElement($nota);
        return $this;
    }

    /**
     * @return Collection<int, TravelSegmentoImagen>
     */
    public function getImagenes(): Collection
    {
        return $this->imagenes;
    }

    public function addImagen(TravelSegmentoImagen $imagen): self
    {
        if (!$this->imagenes->contains($imagen)) {
            $this->imagenes->add($imagen);
            $imagen->setSegmento($this);
        }
        return $this;
    }

    public function removeImagen(TravelSegmentoImagen $imagen): self
    {
        if ($this->imagenes->removeElement($imagen)) {
            if ($imagen->getSegmento() === $this) {
                $imagen->setSegmento(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, TravelSegmentoComponente>
     */
    public function getSegmentoComponentes(): Collection
    {
        return $this->segmentoComponentes;
    }

    public function addSegmentoComponente(TravelSegmentoComponente $segmentoComponente): self
    {
        if (!$this->segmentoComponentes->contains($segmentoComponente)) {
            $this->segmentoComponentes->add($segmentoComponente);
            $segmentoComponente->setSegmento($this);
        }
        return $this;
    }

    public function removeSegmentoComponente(TravelSegmentoComponente $segmentoComponente): self
    {
        if ($this->segmentoComponentes->removeElement($segmentoComponente)) {
            if ($segmentoComponente->getSegmento() === $this) {
                $segmentoComponente->setSegmento(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, TravelItinerarioSegmentoRel>
     *
     * @return Collection<int, TravelItinerarioSegmentoRel>
     */
    public function getItinerarioSegmentosInyectados(): Collection
    {
        return $this->itinerarioSegmentosInyectados;
    }

    // 🔥 VIRTUALES PARA EASYADMIN (TextField compatibles)
    public function getVirtualLogistica(): string { return ''; }
    public function getVirtualTitulo(): string { return ''; }
    public function getVirtualServicios(): string { return ''; }
    public function getVirtualNotas(): string { return ''; }
    public function getVirtualItinerarios(): string { return ''; }
    public function getVirtualGaleria(): string { return ''; }

    #[Assert\Callback]
    public function validateTituloEspanol(ExecutionContextInterface $context, mixed $payload): void
    {
        $hasValidSpanish = false;

        // Sin `is_array()`: la propiedad ya está declarada como lista de traducciones.
        foreach ($this->titulo as $item) {
            if (isset($item['language'], $item['content']) && $item['language'] === 'es') {
                if (trim(strip_tags((string) $item['content'])) !== '') {
                    $hasValidSpanish = true;
                    break;
                }
            }
        }
        if (!$hasValidSpanish) {
            $context->buildViolation('El título público en Español es obligatorio.')->atPath('titulo')->addViolation();
        }
    }

    // ── Dónde empieza y dónde termina ──────────────────────────────────────────

    public function getInicioModo(): PuntoModoEnum { return $this->inicioModo; }
    public function setInicioModo(PuntoModoEnum $v): self { $this->inicioModo = $v; return $this; }

    public function getInicioPunto(): ?TravelPunto { return $this->inicioPunto; }
    public function setInicioPunto(?TravelPunto $v): self { $this->inicioPunto = $v; return $this; }

    public function getFinModo(): PuntoModoEnum { return $this->finModo; }
    public function setFinModo(PuntoModoEnum $v): self { $this->finModo = $v; return $this; }

    public function getFinPunto(): ?TravelPunto { return $this->finPunto; }
    public function setFinPunto(?TravelPunto $v): self { $this->finPunto = $v; return $this; }

    /**
     * Un modo `FIJO` sin punto es peor que no haber declarado nada.
     *
     * Sin declarar, la orden dice «falta el dato» y alguien lo mira. Declarado a medias, dice
     * que hay un punto fijo y no sabe cuál: se cuela como si estuviera resuelto y sale una
     * orden sin sitio de recojo, que es exactamente el fallo que esto viene a quitar.
     */
    #[Assert\Callback]
    public function validarPuntos(ExecutionContextInterface $context, mixed $payload): void
    {
        if ($this->inicioModo->exigePunto() && $this->inicioPunto === null) {
            $context->buildViolation('Has marcado un punto fijo de inicio pero no has elegido cuál.')
                ->atPath('inicioPunto')
                ->addViolation();
        }

        if ($this->finModo->exigePunto() && $this->finPunto === null) {
            $context->buildViolation('Has marcado un punto fijo de fin pero no has elegido cuál.')
                ->atPath('finPunto')
                ->addViolation();
        }
    }

    /**
     * Lo que se le dice al proveedor sobre un extremo, o `null` si todavía no se sabe.
     *
     * `null` es una respuesta legítima y hay que dejarla salir: quien componga la orden tiene
     * que poder escribir «pendiente de confirmar» en vez de inventarse un hotel. El modo
     * `ALOJAMIENTO` también devuelve `null` aquí a propósito — el segmento sabe que es el hotel,
     * pero *cuál* hotel sólo se sabe con un pasajero delante, y eso se resuelve al emitir.
     */
    public function textoDelInicio(): ?string
    {
        return $this->inicioModo === PuntoModoEnum::FIJO ? $this->inicioPunto?->paraLaOrden() : null;
    }

    public function textoDelFin(): ?string
    {
        return $this->finModo === PuntoModoEnum::FIJO ? $this->finPunto?->paraLaOrden() : null;
    }

    /** ¿Están declarados los dos extremos? Es lo que mide cuánto queda por rellenar. */
    public function tienePuntosDeclarados(): bool
    {
        return $this->inicioModo->esDeclarado() && $this->finModo->esDeclarado();
    }

    /**
     * 🔥 VIRTUAL PARA EASYADMIN — «recojo → entrega» de un vistazo.
     *
     * El índice es donde de verdad se ve cuánto queda por declarar: con 100+ segmentos, ir
     * abriendo fichas para descubrir cuáles están sin punto es lo que hace que el campo se
     * quede a medias para siempre.
     */
    public function getVirtualPuntos(): string
    {
        $pinta = static function (PuntoModoEnum $modo, ?TravelPunto $punto): string {
            return match ($modo) {
                PuntoModoEnum::SIN_DEFINIR => '<span style="color:#b00;">?</span>',
                PuntoModoEnum::ALOJAMIENTO => '<span style="color:#2b5cad;">hotel</span>',
                PuntoModoEnum::FIJO => htmlspecialchars(
                    $punto?->getNombre() ?? '⚠ sin punto',
                    ENT_QUOTES,
                    'UTF-8'
                ),
            };
        };

        return sprintf(
            '<span style="white-space:nowrap;font-size:12px;">%s → %s</span>',
            $pinta($this->inicioModo, $this->inicioPunto),
            $pinta($this->finModo, $this->finPunto)
        );
    }
}
