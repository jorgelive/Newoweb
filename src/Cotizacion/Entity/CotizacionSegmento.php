<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_segmento')]
#[ORM\HasLifecycleCallbacks]
class CotizacionSegmento
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionCotservicio::class, inversedBy: 'cotsegmentos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionCotservicio $cotservicio = null;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer')]
    private int $dia = 1;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer')]
    private int $orden = 1;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'date_immutable')]
    private ?DateTimeImmutable $fechaAbsoluta = null;

    /**
     * Identificador del segmento maestro del catálogo.
     * Sirve para mantener la trazabilidad con el catálogo y permitir actualizaciones de storytelling.
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $segmentoMaestroId = null;

    /**
     * Dónde empieza y dónde acaba este párrafo, **cuando no hay maestro del que sacarlo**.
     *
     * ── Por qué existe, si ya se decidió que no ─────────────────────────────
     * Los puntos salen del `TravelSegmento` maestro, y se descartó duplicarlos aquí para no tener
     * **dos superficies declarando el mismo hecho**. Ese argumento vale mientras haya maestro.
     *
     * Un párrafo escrito a mano —el traslado a «La Olla de Juanita», que no está en ningún
     * catálogo ni va a estarlo— **no tiene maestro**, así que no hay primera superficie: no se
     * duplica nada, es el único sitio donde se puede decir. Sin esto, el único lugar para
     * declararlo era el override de la orden ya emitida: dos capas más abajo y sólo después de
     * confirmar.
     *
     * ⚠️ **Con maestro, manda el maestro.** No es un override: si el párrafo viene del catálogo,
     * estos campos ni se leen ni se enseñan. Una regla de precedencia entre los dos sería
     * exactamente la segunda superficie que se quería evitar.
     *
     * Texto libre y no un `TravelPunto`: dar de alta un punto maestro para una parada irrepetible
     * es más caro que el problema, y quien lo lee es una persona.
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $inicioTexto = null;

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write'])]
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $finTexto = null;

    /**
     * El nombre del TRAMO: «Vuelo desde la ciudad de Lima a la ciudad de Cusco».
     *
     * Es el de en medio del árbol: agrupa los componentes de un mismo trayecto. Ver
     * `docs/Cotizaciones.md` §2.b.
     *
     * ⚠️ **Sólo lo lee `pax/`.** En La Biblia el nombre del segmento NO sale de aquí: se resuelve
     * en vivo contra el maestro (`travel_segmento.nombre_interno`, vía `segmentoUnicoMaestroId`),
     * porque el cuadro quiere el nombre **operativo** y esto es el **público**. Son dos textos
     * distintos para la misma cosa y conviven a propósito.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $tituloSnapshot = [];

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'html')]
    #[ORM\Column(type: 'json')]
    private array $contenidoSnapshot = [];

    /** @var list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $imagenesSnapshot = [];

    /**
     * SNAPSHOT: Almacena un array plano con las notas y recomendaciones vigentes al momento de cotizar.
     * Estructura: [ {"nombreInterno": "...", "contenido": [...]}, ... ]
     */
    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'cotizacion:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'json')]
    private array $notasSnapshot = [];

    /** @var Collection<int, CotizacionCotcomponente> */
    #[ORM\OneToMany(mappedBy: 'cotsegmento', targetEntity: CotizacionCotcomponente::class)]
    private Collection $cotcomponentes;

    public function __construct()
    {
        $this->initializeId();
        $this->cotcomponentes = new ArrayCollection();
    }

    public function duplicar(): self
    {
        $copia = clone $this;   // clone superficial por defecto (sin __clone)
        $copia->resetId();

        // Limpiar la colección inversa: los componentes se re-vinculan desde
        // CotizacionCotservicio::duplicar() a través del mapa de segmentos.
        $copia->cotcomponentes = new ArrayCollection();

        return $copia;
    }

    #[Groups(['cotizacion:read', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['cotizacion:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;
        return $this;
    }

    // --- MÉTODOS SOBRESCRITOS PARA EXPONER EL FLAG A API PLATFORM ---
    #[Groups(['cotizacion:write', 'cotizacion:read'])]
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

    public function getCotservicio(): ?CotizacionCotservicio { return $this->cotservicio; }
    public function setCotservicio(?CotizacionCotservicio $cotservicio): self { $this->cotservicio = $cotservicio; return $this; }

    public function getDia(): int { return $this->dia; }
    public function setDia(int $dia): self { $this->dia = $dia; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function getFechaAbsoluta(): ?DateTimeImmutable { return $this->fechaAbsoluta; }
    public function setFechaAbsoluta(DateTimeImmutable $fechaAbsoluta): self { $this->fechaAbsoluta = $fechaAbsoluta; return $this; }

    /**
     * Obtiene el identificador del segmento maestro asociado.
     * Existe para permitir la recarga de textos e imágenes desde el catálogo oficial,
     * manteniendo la vinculación original de la plantilla.
     *
     * @return string|null El UUID del segmento maestro o null si es un segmento personalizado.
     */
    public function getSegmentoMaestroId(): ?string
    {
        return $this->segmentoMaestroId;
    }

    public function getInicioTexto(): ?string { return $this->inicioTexto; }
    public function setInicioTexto(?string $v): self { $this->inicioTexto = $v !== null ? (trim($v) ?: null) : null; return $this; }

    public function getFinTexto(): ?string { return $this->finTexto; }
    public function setFinTexto(?string $v): self { $this->finTexto = $v !== null ? (trim($v) ?: null) : null; return $this; }

    /**
     * Establece el identificador del segmento maestro asociado.
     *
     * @param string|null $segmentoMaestroId El UUID del segmento maestro.
     * @return self
     */
    public function setSegmentoMaestroId(?string $segmentoMaestroId): self
    {
        $this->segmentoMaestroId = $segmentoMaestroId;
        return $this;
    }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTituloSnapshot(): array { return $this->tituloSnapshot; }
    /**
     * @param list<array{language?: string, content?: string|null}> $tituloSnapshot
     */
    public function setTituloSnapshot(array $tituloSnapshot): self { $this->tituloSnapshot = $tituloSnapshot; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getContenidoSnapshot(): array { return $this->contenidoSnapshot; }
    /**
     * @param list<array{language?: string, content?: string|null}> $contenidoSnapshot
     */
    public function setContenidoSnapshot(array $contenidoSnapshot): self { $this->contenidoSnapshot = $contenidoSnapshot; return $this; }

    /**
     * @return list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}>
     */
    public function getImagenesSnapshot(): array { return $this->imagenesSnapshot; }
    /**
     * @param list<array{orden?: int, imageUrl?: string, imageName?: string, imageSize?: int, isPortada?: bool}> $imagenesSnapshot
     */
    public function setImagenesSnapshot(array $imagenesSnapshot): self { $this->imagenesSnapshot = $imagenesSnapshot; return $this; }

    /**
     * Obtiene el snapshot inmutable de notas asociadas a este segmento cotizado.
     *
     * @return array Retorna la estructura JSON con las notas congeladas.
     *
     * @return list<array<string, mixed>>
     */
    public function getNotasSnapshot(): array
    {
        return $this->notasSnapshot;
    }

    /**
     * Establece el snapshot inmutable de notas para este segmento cotizado.
     *
     * @param array $notasSnapshot Array multidimensional con el historial de notas.
     * @return self
     *
     * @param list<array<string, mixed>> $notasSnapshot
     */
    public function setNotasSnapshot(array $notasSnapshot): self
    {
        $this->notasSnapshot = $notasSnapshot;
        return $this;
    }

    /**
     * @return Collection<int, CotizacionCotcomponente>
     */
    public function getCotcomponentes(): Collection { return $this->cotcomponentes; }
    public function addCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if (!$this->cotcomponentes->contains($cotcomponente)) {
            $this->cotcomponentes->add($cotcomponente);
            $cotcomponente->setCotsegmento($this);
        }
        return $this;
    }
    public function removeCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if ($this->cotcomponentes->removeElement($cotcomponente)) {
            if ($cotcomponente->getCotsegmento() === $this) { $cotcomponente->setCotsegmento(null); }
        }
        return $this;
    }
}