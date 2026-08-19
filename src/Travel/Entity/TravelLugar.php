<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Centro de operación / lugar con el que se etiquetan los componentes.
 *
 * Vocabulario PLANO y deliberadamente ambiguo: «Lima» significa a la vez *ocurre en* y
 * *se opera desde*. No es un error de modelado, es el requisito — el operador busca al ojo
 * y no quiere decidir de qué eje se trata antes de filtrar.
 *
 * Se descartó la jerarquía a propósito: Cusco es una ciudad y Valle Sagrado una región
 * fuera de ella, así que «Valle Sagrado dentro de Cusco» sería falso y obligaría a llenar
 * el árbol de excepciones. En su lugar, un tour del Valle que se despacha desde Cusco lleva
 * las DOS etiquetas. Multivaluado y manual.
 *
 * El etiquetado vive sólo aquí, en el catálogo: no se copia a la cotización ni a operaciones.
 * Es una decisión explícita para que re-etiquetar se refleje al instante en el cuadro de
 * tráfico sin re-aplicar nada. Ver `OperacionServicioLugarExtension`.
 */
#[ApiFilter(BooleanFilter::class, properties: ['activo'])]
#[ApiResource(
    shortName: 'Lugar',
    operations: [
        // Genera: GET /travel/lugares — sin uriTemplate saldría el feo '/lugars'.
        new GetCollection(
            uriTemplate: '/lugares',
            normalizationContext: ['groups' => ['lugar:read']],
            // Quien pinta los chips del cuadro de tráfico es el operador de Operaciones, que
            // puede no tener permisos de maestros. Sin este `or` se comería un 403 al cargar.
            security: "is_granted('" . Roles::MAESTROS_SHOW . "') or is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),

        // Genera: GET /travel/lugares/{id}
        new Get(
            uriTemplate: '/lugares/{id}',
            normalizationContext: ['groups' => ['lugar:read']],
            security: "is_granted('" . Roles::MAESTROS_SHOW . "') or is_granted('" . Roles::OPERACIONES_SHOW . "')"
        ),

        // Genera: POST /travel/lugares
        new Post(
            uriTemplate: '/lugares',
            denormalizationContext: ['groups' => ['lugar:write']],
            securityPostDenormalize: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear lugares.'
        ),

        // Genera: PUT /travel/lugares/{id}
        new Put(
            uriTemplate: '/lugares/{id}',
            denormalizationContext: ['groups' => ['lugar:write']],
            security: "is_granted('" . Roles::MAESTROS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar lugares.'
        ),

        // Genera: DELETE /travel/lugares/{id}
        new Delete(
            uriTemplate: '/lugares/{id}',
            security: "is_granted('" . Roles::MAESTROS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar lugares.'
        ),
    ],
    routePrefix: '/travel',
    order: ['orden' => 'ASC', 'nombre' => 'ASC']
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_lugar')]
#[ORM\UniqueConstraint(name: 'uniq_travel_lugar_nombre', columns: ['nombre'])]
#[ORM\HasLifecycleCallbacks]
class TravelLugar
{
    use IdTrait;
    use TimestampTrait;

    /**
     * El `unique` es lo que impide que acaben conviviendo «Cusco» y «cusco » como dos
     * etiquetas distintas, que es como se degrada un vocabulario manual.
     */
    #[Assert\NotBlank(message: 'El nombre del lugar es obligatorio.')]
    #[Groups(['lugar:read', 'lugar:write'])]
    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private ?string $nombre = null;

    /**
     * La fila de chips se lee de izquierda a derecha: Lima y Cusco delante, Nazca al final.
     * Alfabético no sirve — pondría Arequipa antes que Cusco.
     */
    #[Assert\PositiveOrZero]
    #[Groups(['lugar:read', 'lugar:write'])]
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $orden = 0;

    /**
     * Permite retirar una etiqueta del cuadro SIN destruir el etiquetado. Borrar el lugar
     * arrastra en cascada todas sus filas del pool y no hay vuelta atrás; desactivar no.
     */
    #[Groups(['lugar:read', 'lugar:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /**
     * 🚫 CORTE CIRCULAR: lado inverso sin grupos de lectura. El dueño es TravelComponente.
     *
     * @var Collection<int, TravelComponente>
     */
    #[ORM\ManyToMany(targetEntity: TravelComponente::class, mappedBy: 'lugares')]
    private Collection $componentes;

    /**
     * 🚫 CORTE CIRCULAR: lado inverso, sin grupos. El dueño es TravelOrganizacion.
     *
     * Aquí el lugar significa COBERTURA —hasta dónde llega ese organizacion—, mientras que en
     * `$componentes` significa dónde ocurre o desde dónde se despacha ese servicio. Mismo
     * vocabulario, dos preguntas distintas.
     *
     * @var Collection<int, TravelOrganizacion>
     */
    #[ORM\ManyToMany(targetEntity: TravelOrganizacion::class, mappedBy: 'lugares')]
    private Collection $organizaciones;

    public function __construct()
    {
        $this->initializeId();
        $this->componentes = new ArrayCollection();
        $this->organizaciones = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombre ?? 'Sin nombre';
    }

    #[Groups(['lugar:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;

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

    public function isActivo(): bool
    {
        return $this->activo;
    }

    public function setActivo(bool $activo): self
    {
        $this->activo = $activo;

        return $this;
    }

    /**
     * @return Collection<int, TravelComponente>
     *
     * @return Collection<int, TravelComponente>
     */
    public function getComponentes(): Collection
    {
        return $this->componentes;
    }

    /**
     * Delega en el lado dueño a propósito: es lo que permite etiquetar en masa desde el
     * formulario del lugar (EasyAdmin con `by_reference: false`). Sin esta delegación el
     * formulario guarda sin error y no persiste nada.
     */
    public function addComponente(TravelComponente $componente): self
    {
        if (!$this->componentes->contains($componente)) {
            $this->componentes->add($componente);
            $componente->addLugar($this);
        }

        return $this;
    }

    public function removeComponente(TravelComponente $componente): self
    {
        if ($this->componentes->removeElement($componente)) {
            $componente->removeLugar($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, TravelOrganizacion>
     *
     * @return Collection<int, TravelOrganizacion>
     */
    public function getOrganizaciones(): Collection
    {
        return $this->organizaciones;
    }

    /** Delega en el lado dueño, igual que `addComponente()`: es lo que hace que el
     *  etiquetado masivo desde la ficha del lugar persista. */
    public function addOrganizacion(TravelOrganizacion $organizacion): self
    {
        if (!$this->organizaciones->contains($organizacion)) {
            $this->organizaciones->add($organizacion);
            $organizacion->addLugar($this);
        }

        return $this;
    }

    public function removeOrganizacion(TravelOrganizacion $organizacion): self
    {
        if ($this->organizaciones->removeElement($organizacion)) {
            $organizacion->removeLugar($this);
        }

        return $this;
    }

    // 🔥 VIRTUALES PARA EASYADMIN

    /**
     * Alcance del lugar antes de borrarlo: el DELETE arrastra el etiquetado en cascada,
     * así que conviene ver cuánto se lleva por delante.
     */
    public function getVirtualComponentesCount(): string
    {
        $total = $this->componentes->count();

        if (0 === $total) {
            return '<span class="badge badge-secondary">Sin uso</span>';
        }

        return sprintf(
            '<span style="background:#e8f0fe;color:#2b5cad;border-radius:12px;padding:3px 10px;'
            . 'font-size:12px;font-weight:600;white-space:nowrap;">%d componente%s</span>',
            $total,
            1 === $total ? '' : 's'
        );
    }
}
