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

    /**
     * Dónde va este servicio dentro de su día, cuando el reloj no lo decide.
     *
     * **`0` significa «automático»**, y es el valor de casi todos: el servicio se coloca por la
     * hora más temprana de sus componentes, y los que no tienen hora por la naturaleza de lo que
     * son ({@see ComponenteTipoEnum::ordenNarrativo()}: llegar y moverse abre la jornada,
     * dormir la cierra). Cualquier número mayor que 0 es una decisión de una persona y **pisa** a
     * ese automático dentro de su día.
     *
     * ## Por qué hacía falta
     *
     * El desempate entre dos servicios sin hora era, en la guía, el `orden` mínimo de sus
     * segmentos — un número pensado para ordenar DENTRO de un servicio, usado para comparar
     * ENTRE servicios. Cada plantilla empieza por su segmento 1, así que valía 1 para todos y el
     * resultado lo decidía el orden de inserción. En el editor era peor: un `return 0` explícito.
     *
     * ## Lo que se aceptó al hacerlo pisable
     *
     * Que una posición manual **pueda contradecir al reloj**. Se prefirió eso a no dar control:
     * la contradicción la caza un chequeo (`orden-contradice-hora`), que es una señal explícita,
     * mientras que «la vista se ve rara y alguien lo nota» es una señal accidental — y de ésas
     * este código ya se ha quemado bastante.
     *
     * ⚠️ **Un servicio multidía tiene un solo número para varias apariciones.** Hoy no muerde:
     * los siete que existen son escalón 0 en todos sus días, así que se colocan por hora y este
     * campo no llega a decidir. El día que uno se quede sin hora en alguno de sus días, sí.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /**
     * El nombre del DÍA / bloque del itinerario: «Transporte en Cusco», «Full Day Paracas».
     *
     * Los dos nombres del día, ya con el vocabulario unificado (27/08/2026):
     *
     * ```
     * nombreInternoSnapshot  «Full Day HUAYNA: MAPI OLLA CUZ (bimodal)»   ← éste, y va a La Biblia
     * tituloSnapshot         «Excursión a Huayna Picchu de 1 día»         ← al huésped
     * ```
     *
     * ⚠️ **Aquí el interno es el que ve el tráfico** y el título el que ve el huésped, al revés
     * de lo que sugiere la intuición: La Biblia es nuestra, no del cliente. Ver
     * `docs/Cotizaciones.md` §2.b.
     *
     * Éste es el que La Biblia congela como `contextoServicio` y enseña en pequeño bajo el nombre
     * del componente.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $nombreInternoSnapshot = [];

    /**
     * Cómo se llamaba LA PLANTILLA el día que se aplicó. Congelado y de sólo lectura.
     *
     * Su única función es dejar constancia del origen: el operador edita libremente
     * `$nombreInternoSnapshot`, y esto sigue diciendo de dónde salió el servicio. Por eso son dos
     * campos y no uno, aunque nazcan iguales.
     *
     * ⚠️ Congela el **nombre interno** de la plantilla, no su título. Hasta el 27/08/2026 guardaba
     * el título —«Excursión de día completo a Paracas y la Huacachina»— y se llamaba
     * `itinerarioNombreSnapshot`, que no decía cuál de los dos era. Lo que sirve aquí es el
     * operativo, «Full Day Paracas y Huacachina»: esto no lo ve el cliente (no está en
     * `pax_cotizacion:read`) y quien lo lee busca la plantilla en el catálogo.
     *
     * ⚠️ Y por eso **no lleva `#[AutoTranslate]`**: traducir a siete idiomas un nombre que sólo
     * leemos nosotros es trabajo tirado. Regla en `docs/Cotizaciones.md` §2.b — si está traducido
     * es para el cliente; si no, es para nosotros.
     *
     * `[{language:'es', content:'Sin plantilla'}]` cuando el servicio no viene de ninguna.
     *
     * @var list<array{language?: string, content?: string|null}>
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read'])]
    #[ORM\Column(type: 'json')]
    private array $itinerarioNombreInternoSnapshot = [];

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

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'cotizacion:item:read', 'pax_cotizacion:read'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $tituloSnapshot = [];

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

    public function getOrden(): int
    {
        return $this->orden;
    }

    public function setOrden(int $orden): self
    {
        $this->orden = $orden;

        return $this;
    }

    public function getCotizacion(): ?Cotizacion { return $this->cotizacion; }
    public function setCotizacion(?Cotizacion $cotizacion): self { $this->cotizacion = $cotizacion; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getNombreInternoSnapshot(): array { return $this->nombreInternoSnapshot; }
    /**
     * @param list<array{language?: string, content?: string|null}> $nombreInternoSnapshot
     */
    public function setNombreInternoSnapshot(array $nombreInternoSnapshot): self { $this->nombreInternoSnapshot = $nombreInternoSnapshot; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getItinerarioNombreInternoSnapshot(): array { return $this->itinerarioNombreInternoSnapshot; }
    /**
     * @param list<array{language?: string, content?: string|null}> $itinerarioNombreInternoSnapshot
     */
    public function setItinerarioNombreInternoSnapshot(array $itinerarioNombreInternoSnapshot): self { $this->itinerarioNombreInternoSnapshot = $itinerarioNombreInternoSnapshot; return $this; }

    public function getItinerarioMaestroId(): ?string { return $this->itinerarioMaestroId; }
    public function setItinerarioMaestroId(?string $itinerarioMaestroId): self { $this->itinerarioMaestroId = $itinerarioMaestroId; return $this; }

    /**
     * @return list<array{language?: string, content?: string|null}>
     */
    public function getTituloSnapshot(): array { return $this->tituloSnapshot; }
    /**
     * @param list<array{language?: string, content?: string|null}> $tituloSnapshot
     */
    public function setTituloSnapshot(array $tituloSnapshot): self { $this->tituloSnapshot = $tituloSnapshot; return $this; }

    public function getFechaInicioAbsoluta(): ?DateTimeImmutable { return $this->fechaInicioAbsoluta; }
    public function setFechaInicioAbsoluta(?DateTimeImmutable $fechaInicioAbsoluta): self { $this->fechaInicioAbsoluta = $fechaInicioAbsoluta; return $this; }

    /**
     * @return Collection<int, CotizacionCotcomponente>
     */
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

    /**
     * @return Collection<int, CotizacionSegmento>
     */
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

}
