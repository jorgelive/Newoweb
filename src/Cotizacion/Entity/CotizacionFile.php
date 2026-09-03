<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use App\Api\Provider\Cotizacion\CotizacionFileCollectionProvider;
use App\Cotizacion\ApiPlatform\State\CotizacionFileItemProvider;
use App\Api\Provider\Cotizacion\CotizacionFilePublicProvider;
use App\Cotizacion\ApiPlatform\Filter\CotizacionFileNombreFilter;
use App\Cotizacion\Enum\FileEstadoEnum;
use App\Cotizacion\Enum\FileModoEnum;
use App\Entity\Maestro\MaestroContacto;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Trait\IdTrait;
use Symfony\Component\Uid\Uuid;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * El Expediente raíz. Agrupa todas las propuestas comerciales de un cliente o grupo.
 *
 * Vista pública (por localizador) en dos niveles:
 *   - pax_file:read→ PORTADA: datos del File + resúmenes de propuestas.
 *   - pax_cotizacion:read → DETALLE: agrega la cotización completa de UNA versión.
 */
#[ApiFilter(CotizacionFileNombreFilter::class)]
// El dashboard arranca acotado a los abiertos: sin esto sólo se podía paginar sobre todo, y un
// expediente ganado en marzo empujaba hacia abajo a los que sí hay que trabajar hoy.
#[ApiFilter(SearchFilter::class, properties: ['estado' => 'exact'])]
#[ApiResource(
    shortName: 'CotizacionFile',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['file:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            provider: CotizacionFileCollectionProvider::class,
        ),
        new Get(
            normalizationContext: ['groups' => ['file:read', 'file:item:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
            // Añade `filasOperacionActivas` a cada propuesta, en UNA consulta. Ver el provider.
            provider: CotizacionFileItemProvider::class
        ),
        // PORTADA pública: File + cards de propuestas (liviano)
        new Get(
            uriTemplate: '/client/cotizacion/cotizacion_file/{localizador}',
            uriVariables: [
                'localizador' => new Link(fromClass: CotizacionFile::class, identifiers: ['localizador']),
            ],
            normalizationContext: ['groups' => ['pax_file:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CotizacionFilePublicProvider::class,
        ),
        // DETALLE público: File + cotización completa de una versión
        new Get(
            uriTemplate: '/client/cotizacion/cotizacion_file/{localizador}/{propuesta}',
            uriVariables: [
                'localizador' => new Link(fromClass: CotizacionFile::class, identifiers: ['localizador']),
                'propuesta'     => new Link(fromClass: CotizacionFile::class, identifiers: ['propuesta']),
            ],
            normalizationContext: ['groups' => ['pax_file:read', 'pax_cotizacion:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: CotizacionFilePublicProvider::class,
        ),
        new Post(
            denormalizationContext: ['groups' => ['file:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear expedientes.'
        ),
        new Put(
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar expedientes.'
        ),
        new Patch(
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para actualizar parcialmente expedientes.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar expedientes.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file')]
#[ORM\Index(columns: ['created_at'], name: 'idx_cotizacion_file_created_at')]
#[ORM\HasLifecycleCallbacks]
class CotizacionFile
{
    use IdTrait;
    use TimestampTrait;
    use LocatorTrait;

    // operacion:item:read — La Biblia embebe el file en cada servicio. Sin estos dos campos
    // en el grupo, la API lo serializa como stub (@id + timestamps) y el cuadro de tráfico
    // no puede decir de quién es la fila. Ver docs/Operacion.md §6.
    #[Groups(['file:read', 'file:item:read', 'file:write', 'pax_file:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreGrupo = null;

    #[Groups(['file:read', 'file:item:read', 'file:write', 'pax_file:read', 'operacion:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $pasajeroPrincipal = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $email = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $telefono = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $pais = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    /**
     * La PERSONA titular del expediente, cuando se sabe cuál es.
     *
     * ⚠️ `nombreGrupo`, `telefono` y `email` de aquí al lado **siguen siendo la verdad**. Esto
     * es el enganche para dejar de teclear a la misma persona en tres módulos: hoy el mismo
     * cliente está escrito en `pms_reserva` como `nombreCliente`, aquí como `nombreGrupo` y en
     * la conversación como `guestName`, y corregirlo en uno no lo corrige en los otros.
     *
     * Nullable porque se puebla a posteriori y porque un expediente puede nacer sin teléfono
     * con el que reconocer a nadie. Ver docs/Mensajeria.md §20.
     */
    #[ORM\ManyToOne(targetEntity: MaestroContacto::class)]
    #[ORM\JoinColumn(name: 'contacto_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MaestroContacto $contacto = null;

    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroIdioma $idioma = null;

    #[Groups(['file:read', 'file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 5, options: ['default' => 'es'])]
    private string $idiomaCliente = 'es';

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 30, enumType: FileEstadoEnum::class, options: ['default' => 'abierto'])]
    private FileEstadoEnum $estado = FileEstadoEnum::ABIERTO;

    /**
     * @var Collection<int, Cotizacion>
     * EXTRA_LAZY: la vista pública nunca hidrata esta colección (el provider
     * usa queries escalares); el editor la sigue usando con file:item:read.
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: Cotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['propuesta' => 'DESC'])]
    private Collection $cotizaciones;

    /**
     * @var Collection<int, CotizacionFilepasajero>
     */
    // ⚠️ SIN `pax_file:read`: al cliente se lo sirve getManifiestoParaCliente(), que en un
    // expediente de grupo devuelve una lista vacía. Ver FileModoEnum::ocultaManifiesto().
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFilepasajero::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $filepasajeros;

    /**
     * @var Collection<int, CotizacionFilearchivo>
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFilearchivo::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $filearchivos;

    /**
     * Los vuelos del viaje, en orden cronológico.
     *
     * ⚠️ Ordenados por `salida` y no por número: lo que se mira en un expediente es «qué pasa
     * el día 23», y un `JA7027` que vuela el 25 y el 27 no significa nada junto.
     *
     * @var Collection<int, CotizacionVuelo>
     */
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionVuelo::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['salida' => 'ASC'])]
    private Collection $vuelos;

    /**
     * Los subgrupos de este expediente: salones, grupos, habitaciones, reservas aéreas.
     *
     * Cuelgan de aquí y no del sistema: el «Grupo 5» de un viaje no es el de otro. Ver
     * {@see CotizacionFileGrupo}.
     *
     * @var Collection<int, CotizacionFileGrupo>
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFileGrupo::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['tipo' => 'ASC', 'clave' => 'ASC'])]
    private Collection $grupos;

    /**
     * Qué clase de negocio es, y de ahí cómo se comporta todo.
     *
     * Una sola decisión en vez de banderas sueltas —ocultar totales, exigir documento, habilitar
     * padrón—, que eran la misma escrita tres veces. Ver {@see FileModoEnum}.
     */
    #[Groups(['file:read', 'file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 20, enumType: FileModoEnum::class, options: ['default' => 'estandar'])]
    private FileModoEnum $modo = FileModoEnum::ESTANDAR;

    // ══════════════════════════════════════════════════════════════════════
    // PROPIEDADES VIRTUALES DE LA VISTA PÚBLICA (no persistidas)
    // Las llena CotizacionFilePublicProvider; la entity no hace queries.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Resúmenes livianos de las propuestas públicas vigentes (portada).
     * Calculados por el provider con un query escalar (no hidrata entidades).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $propuestasParaCliente = [];

    /** Cotización completa de la versión solicitada en la URL (solo detalle). */
    private ?Cotizacion $cotizacionParaCliente = null;

    public function __construct()
    {
        $this->initializeId();
        $this->initializeLocator();
        $this->cotizaciones = new ArrayCollection();
        $this->filepasajeros = new ArrayCollection();
        $this->filearchivos = new ArrayCollection();
        $this->grupos = new ArrayCollection();
        $this->vuelos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombreGrupo ?? 'File sin nombre';
    }

    /* ======================================================
     * VISTA PÚBLICA (pax)
     * ====================================================== */

    #[Groups(['file:read', 'file:item:read', 'pax_file:read', 'operacion:item:read'])]
    #[SerializedName('localizador')]
    public function getLocalizadorPublico(): ?string
    {
        // Se mapea con la propiedad $this->localizador del Trait
        return $this->localizador;
    }

    /**
     * @param list<array<string, mixed>> $versiones
     */
    public function setPropuestasParaCliente(array $versiones): self
    {
        $this->propuestasParaCliente = $versiones;
        return $this;
    }

    /**
     * Cards de propuestas para la portada: resumen comercial i18n, precio de
     * venta, pax, vigencia y fecha de inicio del viaje. Puede haber varias
     * propuestas activas simultáneas (núcleos turísticos independientes).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Groups(['pax_file:read'])]
    public function getPropuestasParaCliente(): array
    {
        return $this->propuestasParaCliente;
    }

    public function setCotizacionParaCliente(?Cotizacion $cotizacion): self
    {
        $this->cotizacionParaCliente = $cotizacion;
        return $this;
    }

    /**
     * El tramo que ocupa cada versión del expediente —primer servicio y fin del viaje— más su
     * estado y su título. La llena CotizacionFileCollectionProvider con UN query escalar batched
     * para toda la página del dashboard (sin N+1), evitando hidratar
     * $cotizaciones/$cotservicios por cada fila.
     *
     * `fechaFin` NO es `MAX(fechaInicioAbsoluta)` a secas: un viaje puede acabar en un checkout
     * que ya no tiene servicio propio, así que se toma también el fin de las estadías. El porqué
     * de la condición exacta está en el provider.
     *
     * Lleva además el ESTADO y el TÍTULO de cada versión: el dashboard enseñaba «V1: 30 oct.» y
     * nada más, así que un expediente con tres propuestas —una confirmada, una cancelada y un
     * histórico— se leía igual que uno con tres pendientes.
     *
     * @var array<int, array{id: string, propuesta: int, estado: string, titulo: array<int, array<string, mixed>>, fechaInicio: ?string, fechaFin: ?string}>
     */
    private array $propuestasFechas = [];

    /**
     * La misma forma que declara la propiedad. Con `list<array<string, mixed>>` aquí se podía
     * guardar una fila sin `propuesta`, y el getter promete que la trae.
     *
     * @param array<int, array{id: string, propuesta: int, estado: string, titulo: array<int, array<string, mixed>>, fechaInicio: ?string, fechaFin: ?string}> $propuestasFechas
     */
    public function setPropuestasFechas(array $propuestasFechas): self
    {
        $this->propuestasFechas = $propuestasFechas;
        return $this;
    }

    /**
     * La misma forma que declara la propiedad. Anunciaba sólo `propuesta` y `fechaInicio` —dos de
     * las seis claves que realmente viajan—, así que `estado`, `titulo` y las fechas del tramo
     * eran invisibles para quien lo leyera desde fuera.
     *
     * @return array<int, array{id: string, propuesta: int, estado: string, titulo: array<int, array<string, mixed>>, fechaInicio: ?string, fechaFin: ?string}>
     */
    #[Groups(['file:read'])]
    public function getPropuestasFechas(): array
    {
        return $this->propuestasFechas;
    }

    /**
     * Cotización completa expuesta al cliente. Solo la llena el provider en
     * la operación de detalle; en portada es null y su grupo no se serializa.
     */
    #[Groups(['pax_cotizacion:read'])]
    public function getCotizacionParaCliente(): ?Cotizacion
    {
        return $this->cotizacionParaCliente;
    }

    /**
     * Documentos visibles para el cliente en el visor público.
     * Filtra por ArchivoTipoEnum::esPublico() en vez de una lista de
     * strings hardcodeada, para mantener la regla en un solo sitio.
     *
     * @return list<CotizacionFilearchivo>
     */
    #[Groups(['pax_file:read'])]
    public function getDocumentosParaCliente(): array
    {
        return $this->filearchivos->filter(
            fn(CotizacionFilearchivo $archivo) => $archivo->getTipoArchivo()?->esPublico() === true
        )->getValues();
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    /**
     * Se redeclara sobre IdTrait sólo para publicar el id en operacion:item:read.
     *
     * La Biblia necesita construir el IRI del expediente (agrupar servicios en una Orden
     * de Servicio) desde el objeto embebido; sin esto habría que parsear el identificador
     * JSON-LD, que no viaja en los tipos generados de TypeScript.
     *
     * Ojo: el lector de anotaciones de Doctrine sigue activo y parsea estos docblocks;
     * escribir la arroba de JSON-LD aquí rompe el arranque con un "annotation never imported".
     */
    #[Groups(['operacion:item:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function getIdiomaCliente(): string { return $this->idiomaCliente; }
    public function setIdiomaCliente(string $idiomaCliente): self { $this->idiomaCliente = $idiomaCliente; return $this; }

    public function getNombreGrupo(): ?string { return $this->nombreGrupo; }
    public function setNombreGrupo(string $nombreGrupo): self { $this->nombreGrupo = $nombreGrupo; return $this; }

    public function getPasajeroPrincipal(): ?string { return $this->pasajeroPrincipal; }
    public function setPasajeroPrincipal(?string $pasajeroPrincipal): self { $this->pasajeroPrincipal = $pasajeroPrincipal; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getTelefono(): ?string
    {
        if ($this->telefono === null || $this->telefono === '') return null;
        try {
            $util = PhoneNumberUtil::getInstance();
            return $util->format($util->parse('+' . $this->telefono, null), PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $this->telefono;
        }
    }

    public function setTelefono(?string $telefono): self { $this->telefono = $telefono; return $this; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function sanitizarCampos(): void
    {
        if ($this->telefono !== null && $this->telefono !== '') {
            $cleaned = (new PhoneSanitizer())->cleanPhoneNumber($this->telefono, 'PE');
            $this->telefono = $cleaned !== '' ? $cleaned : null;
        }
    }

    public function getEstado(): FileEstadoEnum
    {
        return $this->estado;
    }

    public function setEstado(FileEstadoEnum $estado): static
    {
        $this->estado = $estado;

        return $this;
    }

    /**
     * @return Collection<int, Cotizacion>
     */
    public function getCotizaciones(): Collection { return $this->cotizaciones; }
    public function addCotizacion(Cotizacion $cotizacion): self
    {
        if (!$this->cotizaciones->contains($cotizacion)) {
            $this->cotizaciones->add($cotizacion);
            $cotizacion->setFile($this);
        }
        return $this;
    }
    public function removeCotizacion(Cotizacion $cotizacion): self
    {
        if ($this->cotizaciones->removeElement($cotizacion)) {
            if ($cotizacion->getFile() === $this) { $cotizacion->setFile(null); }
        }
        return $this;
    }

    /**
     * @return Collection<int, CotizacionFilepasajero>
     */
    public function getFilepasajeros(): Collection { return $this->filepasajeros; }
    public function addFilepasajero(CotizacionFilepasajero $filepasajero): self
    {
        if (!$this->filepasajeros->contains($filepasajero)) {
            $this->filepasajeros->add($filepasajero);
            $filepasajero->setFile($this);
        }
        return $this;
    }
    public function removeFilepasajero(CotizacionFilepasajero $filepasajero): self
    {
        if ($this->filepasajeros->removeElement($filepasajero)) {
            if ($filepasajero->getFile() === $this) { $filepasajero->setFile(null); }
        }
        return $this;
    }

    /**
     * @return Collection<int, CotizacionFilearchivo>
     */
    public function getFilearchivos(): Collection { return $this->filearchivos; }
    public function addFilearchivo(CotizacionFilearchivo $filearchivo): self
    {
        if (!$this->filearchivos->contains($filearchivo)) {
            $this->filearchivos->add($filearchivo);
            $filearchivo->setFile($this);
        }
        return $this;
    }
    public function removeFilearchivo(CotizacionFilearchivo $filearchivo): self
    {
        if ($this->filearchivos->removeElement($filearchivo)) {
            if ($filearchivo->getFile() === $this) { $filearchivo->setFile(null); }
        }
        return $this;
    }

    public function getContacto(): ?MaestroContacto { return $this->contacto; }
    public function setContacto(?MaestroContacto $contacto): self { $this->contacto = $contacto; return $this; }

    /** @return Collection<int, CotizacionVuelo> */
    public function getVuelos(): Collection { return $this->vuelos; }

    /** @return Collection<int, CotizacionFileGrupo> */
    public function getGrupos(): Collection { return $this->grupos; }

    public function addGrupo(CotizacionFileGrupo $grupo): self
    {
        if (!$this->grupos->contains($grupo)) {
            $this->grupos->add($grupo);
            $grupo->setFile($this);
        }

        return $this;
    }

    public function removeGrupo(CotizacionFileGrupo $grupo): self
    {
        if ($this->grupos->removeElement($grupo) && $grupo->getFile() === $this) {
            $grupo->setFile(null);
        }

        return $this;
    }

    public function getModo(): FileModoEnum { return $this->modo; }
    public function setModo(FileModoEnum $v): self { $this->modo = $v; return $this; }

    /** Atajos para que quien pregunte no tenga que conocer el enum. */
    #[Groups(['file:read', 'file:item:read', 'pax_file:read'])]
    public function isUsaPadron(): bool { return $this->modo->usaPadron(); }

    /**
     * Ejes en los que falta gente por asignar. Lo rellena `CotizacionFileItemProvider`.
     *
     * Ver {@see \App\Cotizacion\Service\CoberturaDeSubgrupos}: si el vuelo se parte en dos y
     * alguien no está en ninguno, su itinerario no le enseña ningún vuelo — y no puede reportarlo,
     * porque no echa de menos lo que no sabía que existía.
     *
     * @var list<array{eje: string, ejeLabel: string, faltan: list<string>}>
     */
    // ⚠️ La forma se declara AQUÍ y no se castea en el front. De un `list<array{...}>` la
    // introspección saca `{[k: string]: string|string[]}[]`, que no tipa nada: `eje.join` compila.
    // Declarándolo en PHP el arreglo vale para los dos `api.d.ts` y para cualquier consumidor
    // futuro, en vez de para el que lo notó primero.
    #[ApiProperty(openapiContext: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'eje' => ['type' => 'string'],
                'ejeLabel' => ['type' => 'string'],
                'faltan' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['eje', 'ejeLabel', 'faltan'],
        ],
    ])]
    #[Groups(['file:item:read'])]
    private array $subgruposIncompletos = [];

    /**
     * @return list<array{eje: string, ejeLabel: string, faltan: list<string>}>
     */
    public function getSubgruposIncompletos(): array
    {
        return $this->subgruposIncompletos;
    }

    /**
     * @param list<array{eje: string, ejeLabel: string, faltan: list<string>}> $subgruposIncompletos
     */
    public function setSubgruposIncompletos(array $subgruposIncompletos): self
    {
        $this->subgruposIncompletos = $subgruposIncompletos;

        return $this;
    }

    /**
     * El padrón que ve el cliente en la portada pública. **Vacío en un expediente de grupo.**
     *
     * ⚠️ Aquí y no en la vista: esconderlo en el front deja los 133 nombres y sus números de
     * documento viajando en la respuesta, a un F12 de distancia. Lo que no debe salir, no se manda.
     *
     * ⚠️ `SerializedName` a propósito: la API sigue diciendo `filepasajeros`, así que `pax` no
     * cambia — simplemente recibe una lista vacía y su `v-if` ya se encarga.
     *
     * @return Collection<int, CotizacionFilepasajero>
     */
    #[Groups(['pax_file:read'])]
    #[SerializedName('filepasajeros')]
    public function getManifiestoParaCliente(): Collection
    {
        return $this->modo->ocultaManifiesto() ? new ArrayCollection() : $this->filepasajeros;
    }

    #[Groups(['file:read', 'file:item:read', 'pax_file:read'])]
    public function isExigeIdentificacion(): bool { return $this->modo->exigeIdentificacion(); }
}
