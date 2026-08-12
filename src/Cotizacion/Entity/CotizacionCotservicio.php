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
#[ORM\Table(name: 'cotizacion_cotservicio')]
#[ORM\HasLifecycleCallbacks]
class CotizacionCotservicio
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\ManyToOne(targetEntity: Cotizacion::class, inversedBy: 'cotservicios')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Cotizacion $cotizacion = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $nombreSnapshot = [];

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $itinerarioNombreSnapshot = [];

    /**
     * Id del maestro TravelItinerario (plantilla) desde el que se armó este
     * servicio. Solo referencia interna: permite re-sincronizar con exactitud las
     * filas TravelSegmentoComponente ligadas a esa plantilla (p.ej. la promoción
     * "hora de servicio completo", única por plantilla/día). Null si el servicio
     * no proviene de una plantilla o es previo a este campo.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $itinerarioMaestroId = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $nombrePublicoSnapshot = [];

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fechaInicioAbsoluta = null;

    /**
     * @var Collection<int, CotizacionCotcomponente>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    #[ORM\OneToMany(mappedBy: 'cotservicio', targetEntity: CotizacionCotcomponente::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechaHoraInicio' => 'ASC'])]
    private Collection $cotcomponentes;

    /**
     * @var Collection<int, CotizacionSegmento>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    #[ORM\OneToMany(mappedBy: 'cotservicio', targetEntity: CotizacionSegmento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dia' => 'ASC', 'orden' => 'ASC'])]
    private Collection $cotsegmentos;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $servicioMaestroId = null;

    /**
     * Prestador por defecto del día. OPCIONAL.
     *
     * Guarda una INTENCIÓN («este día lo opera X»), no contenido: sirve para que los
     * componentes lo hereden cuando no definen el suyo y para filtrar el selector de
     * tarifas del editor. Por eso sólo lleva id + nombre y no el título público, la
     * URL ni las imágenes — lo que se muestra al cliente se decide y se congela en
     * el componente, que es donde vive el hecho.
     *
     * Nunca sale a pax: no tiene el grupo pax_cotizacion:read.
     *
     * Ver CotizacionCotcomponente::resolverPrestador() y docs/Cotizaciones.md.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 36, nullable: true)]
    private ?string $prestadorMaestroId = null;

    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $prestadorNombreSnapshot = null;

    public function __construct()
    {
        $this->initializeId();
        $this->cotcomponentes = new ArrayCollection();
        $this->cotsegmentos = new ArrayCollection();
    }

    public function duplicar(): self
    {
        $copia = clone $this;
        $copia->resetId();

        $mapaSegmentos = [];
        $copia->cotsegmentos = new ArrayCollection();
        foreach ($this->cotsegmentos as $segmento) {
            $copiaSeg = $segmento->duplicar();
            $copiaSeg->setCotservicio($copia);
            $copia->cotsegmentos->add($copiaSeg);
            $mapaSegmentos[$segmento->getId()->toRfc4122()] = $copiaSeg;
        }

        $copia->cotcomponentes = new ArrayCollection();
        foreach ($this->cotcomponentes as $componente) {
            $copiaComp = $componente->duplicar();
            $copiaComp->setCotservicio($copia);

            $segOriginal = $componente->getCotsegmento();
            $copiaComp->setCotsegmento(
                $segOriginal !== null && isset($mapaSegmentos[$segOriginal->getId()->toRfc4122()])
                    ? $mapaSegmentos[$segOriginal->getId()->toRfc4122()]
                    : null
            );

            $copia->cotcomponentes->add($copiaComp);
        }

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

    public function getCotizacion(): ?Cotizacion { return $this->cotizacion; }
    public function setCotizacion(?Cotizacion $cotizacion): self { $this->cotizacion = $cotizacion; return $this; }

    public function getNombreSnapshot(): array { return $this->nombreSnapshot; }
    public function setNombreSnapshot(array $nombreSnapshot): self { $this->nombreSnapshot = $nombreSnapshot; return $this; }

    public function getItinerarioNombreSnapshot(): array { return $this->itinerarioNombreSnapshot; }
    public function setItinerarioNombreSnapshot(array $itinerarioNombreSnapshot): self { $this->itinerarioNombreSnapshot = $itinerarioNombreSnapshot; return $this; }

    public function getItinerarioMaestroId(): ?string { return $this->itinerarioMaestroId; }
    public function setItinerarioMaestroId(?string $itinerarioMaestroId): self { $this->itinerarioMaestroId = $itinerarioMaestroId; return $this; }

    public function getNombrePublicoSnapshot(): array { return $this->nombrePublicoSnapshot; }
    public function setNombrePublicoSnapshot(array $nombrePublicoSnapshot): self { $this->nombrePublicoSnapshot = $nombrePublicoSnapshot; return $this; }

    public function getFechaInicioAbsoluta(): ?DateTimeImmutable { return $this->fechaInicioAbsoluta; }
    public function setFechaInicioAbsoluta(?DateTimeImmutable $fechaInicioAbsoluta): self { $this->fechaInicioAbsoluta = $fechaInicioAbsoluta; return $this; }

    public function getCotcomponentes(): Collection { return $this->cotcomponentes; }
    public function addCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if (!$this->cotcomponentes->contains($cotcomponente)) {
            $this->cotcomponentes->add($cotcomponente);
            $cotcomponente->setCotservicio($this);
        }
        return $this;
    }
    public function removeCotcomponente(CotizacionCotcomponente $cotcomponente): self
    {
        if ($this->cotcomponentes->removeElement($cotcomponente)) {
            if ($cotcomponente->getCotservicio() === $this) { $cotcomponente->setCotservicio(null); }
        }
        return $this;
    }

    public function getCotsegmentos(): Collection { return $this->cotsegmentos; }
    public function addCotsegmento(CotizacionSegmento $cotsegmento): self
    {
        if (!$this->cotsegmentos->contains($cotsegmento)) {
            $this->cotsegmentos->add($cotsegmento);
            $cotsegmento->setCotservicio($this);
        }
        return $this;
    }
    public function removeCotsegmento(CotizacionSegmento $cotsegmento): self
    {
        if ($this->cotsegmentos->removeElement($cotsegmento)) {
            if ($cotsegmento->getCotservicio() === $this) { $cotsegmento->setCotservicio(null); }
        }
        return $this;
    }

    public function getServicioMaestroId(): ?string { return $this->servicioMaestroId; }
    public function setServicioMaestroId(?string $servicioMaestroId): self { $this->servicioMaestroId = $servicioMaestroId; return $this; }

    public function getPrestadorMaestroId(): ?string { return $this->prestadorMaestroId; }
    public function setPrestadorMaestroId(?string $v): self { $this->prestadorMaestroId = $v; return $this; }

    public function getPrestadorNombreSnapshot(): ?string { return $this->prestadorNombreSnapshot; }
    public function setPrestadorNombreSnapshot(?string $v): self { $this->prestadorNombreSnapshot = $v; return $this; }

    /** ¿El día fija un prestador por defecto para sus componentes? */
    public function tienePrestadorPropio(): bool
    {
        return $this->prestadorMaestroId !== null
            || trim($this->prestadorNombreSnapshot ?? '') !== '';
    }
}