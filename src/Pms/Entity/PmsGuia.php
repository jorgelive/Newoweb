<?php

declare(strict_types=1);

namespace App\Pms\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use App\Api\Provider\Pms\PmsGuiaHuespedProvider;
use App\Api\Provider\Pms\PmsUnidadCatalogoProvider;
use App\Attribute\AutoTranslate;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaAccesoEstado;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'pms_guia')]
#[ORM\HasLifecycleCallbacks]
/**
 * PmsGuia centraliza la información de la guía para el huésped.
 * La operación principal se accede vía el UUID de la unidad vinculada.
 */

#[ApiResource(
    operations: [
        // ════════════════════════════════════════════════════════════════
        // GUÍA DEL HUÉSPED — por localizador de reserva
        // Es el enlace que se reparte a los clientes. El localizador ya
        // identifica la estancia, así que no hace falta exponerle al huésped
        // ningún UUID interno ni encadenar dos peticiones para resolverlo.
        // ════════════════════════════════════════════════════════════════
        new Get(
            uriTemplate: '/client/pax/pms/pms_guia/{localizador}',
            uriVariables: [
                'localizador' => new Link(fromClass: PmsReserva::class, identifiers: ['localizador']),
            ],
            normalizationContext: ['groups' => ['pax_guia:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PmsGuiaHuespedProvider::class,
        ),
        // Reserva con varias estancias: se elige una por slug de unidad.
        new Get(
            uriTemplate: '/client/pax/pms/pms_guia/{localizador}/{unidad}',
            uriVariables: [
                'localizador' => new Link(fromClass: PmsReserva::class, identifiers: ['localizador']),
                'unidad'      => new Link(fromClass: PmsUnidad::class, identifiers: ['slug']),
            ],
            normalizationContext: ['groups' => ['pax_guia:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PmsGuiaHuespedProvider::class,
        ),
    ],
    order: ['createdAt' => 'DESC']
)]
class PmsGuia
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    #[ORM\OneToOne(inversedBy: 'guia', targetEntity: PmsUnidad::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'unidad_id', referencedColumnName: 'id', nullable: false)]
    private ?PmsUnidad $unidad = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /** @var list<array{language?: string, content?: string|null}> Lista de pares por idioma; la forma la impone `MaestroIdioma::normalizarParaDB()`. */
    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull]
    private array $titulo = [];

    /** @var Collection<int, PmsGuiaHasSeccion> */
    #[ORM\OneToMany(mappedBy: 'guia', targetEntity: PmsGuiaHasSeccion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC'])]
    private Collection $guiaHasSecciones;

    // ══════════════════════════════════════════════════════════════════════
    // PROPIEDADES VIRTUALES DE LA VISTA PÚBLICA (no persistidas)
    // Las llenan los providers de App\Api\Provider\Pms; la entity no calcula
    // ni consulta nada. Mismo patrón que CotizacionFile::$versionesParaCliente.
    // ══════════════════════════════════════════════════════════════════════

    /** @var array<int, PmsGuiaSeccion> Árbol ya podado por PmsGuiaArbolFiltro. */
    private array $seccionesParaCliente = [];

    /**
     * Datos de cabecera de la estancia (nombre del huésped, unidad, fechas).
     * Sustituye al bloque `text_fixed` que devolvía el endpoint /guiahelper,
     * con una diferencia: aquí NO viaja ninguna credencial. Los códigos ya
     * están resueltos dentro del cuerpo de cada ítem, o no están.
     *
     * @var array<string, string>
     */
    private array $contextoParaCliente = [];

    /** @var array<int, array<string, mixed>> Redes WiFi; vacío si la ventana no está abierta. */
    private array $redesWifiParaCliente = [];

    /**
     * @var array<int, array<string, mixed>> Medios de cobro que aplican a ESTE huésped,
     *                                       ya filtrados por procedencia.
     */
    private array $mediosPagoParaCliente = [];

    private ?PmsGuiaAcceso $accesoParaCliente = null;


    public function __construct()
    {
        $this->guiaHasSecciones = new ArrayCollection();
        $this->titulo = [];
        $this->id = Uuid::v7();
    }

    // --- MÉTODOS PARA API (Grupos en Getters) ---

    /**
     * Árbol COMPLETO de secciones activas, sin filtrar por visibilidad. No se
     * serializa: es la entrada de PmsGuiaArbolFiltro::podar(), que decide qué
     * sobrevive. Lo que ve el cliente es getSeccionesParaCliente().
     *
     * @return list<PmsGuiaSeccion>
     */
    public function getSeccionesApi(): array
    {
        $relaciones = $this->guiaHasSecciones->filter(fn(PmsGuiaHasSeccion $rel) => $rel->isActivo());

        $secciones = [];
        foreach ($relaciones as $rel) {
            if ($rel->getSeccion()) {
                $secciones[] = $rel->getSeccion();
            }
        }
        return $secciones;
    }

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getUnidad(): ?PmsUnidad { return $this->unidad; }

    public function isActivo(): bool { return $this->activo; }

    /** @return list<array{language?: string, content?: string|null}> */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getTitulo(): array
    {
        return MaestroIdioma::ordenarParaFormulario($this->titulo);
    }

    /* ======================================================
     * VISTA PÚBLICA (pax) — las llenan los providers
     * ====================================================== */

    /**
     * @param list<PmsGuiaSeccion> $secciones
     */
    public function setSeccionesParaCliente(array $secciones): self
    {
        $this->seccionesParaCliente = $secciones;
        return $this;
    }

    /**
     * Secciones visibles para quien pide la guía. Es lo ÚNICO que se serializa:
     * getSeccionesApi() devuelve el árbol completo sin filtrar y no tiene grupo.
     *
     * @return array<int, PmsGuiaSeccion>
     */
    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    #[SerializedName('secciones')]
    public function getSeccionesParaCliente(): array
    {
        return $this->seccionesParaCliente;
    }

    /**
     * @param array<string, mixed> $contexto
     */
    public function setContextoParaCliente(array $contexto): self
    {
        $this->contextoParaCliente = $contexto;
        return $this;
    }

    /** @return array<string, string> */
    #[Groups(['pax_guia:read'])]
    #[SerializedName('contexto')]
    public function getContextoParaCliente(): array
    {
        return $this->contextoParaCliente;
    }

    /**
     * @param list<array<string, mixed>> $redes
     */
    public function setRedesWifiParaCliente(array $redes): self
    {
        $this->redesWifiParaCliente = $redes;
        return $this;
    }

    /**
     * Redes WiFi con su contraseña real. Llega vacío mientras la ventana esté
     * cerrada: no se manda enmascarada. El contrato anterior enviaba
     * `password: '********'` y el navegador deducía el bloqueo comprobando si
     * el texto contenía un asterisco.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Groups(['pax_guia:read'])]
    #[SerializedName('redesWifi')]
    public function getRedesWifiParaCliente(): array
    {
        return $this->redesWifiParaCliente;
    }

    /**
     * @param list<array<string, mixed>> $medios
     */
    public function setMediosPagoParaCliente(array $medios): self
    {
        $this->mediosPagoParaCliente = $medios;
        return $this;
    }

    /**
     * Los medios de cobro que pinta `{{ medios_pago }}`, ya filtrados por procedencia.
     *
     * A diferencia del WiFi, esto **no tiene ventana**: un huésped que aún no ha entrado es
     * justo el que necesita saber por dónde adelantar el pago. Lo que sí lleva es el filtro de
     * audiencia, y por eso se calcula en el provider y no aquí: hace falta la reserva para
     * saber si paga desde Perú, y una entidad no debería tener que averiguarlo.
     *
     * La `nota` viaja como array i18n crudo, sin traducir: la elige el navegador con
     * `maestroStore.traducir()`, igual que el resto de textos de la guía. Traducirla aquí
     * obligaría a que el backend supiera en qué idioma está mirando el huésped ahora mismo.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Groups(['pax_guia:read'])]
    #[SerializedName('mediosPago')]
    public function getMediosPagoParaCliente(): array
    {
        return $this->mediosPagoParaCliente;
    }

    public function setAccesoParaCliente(?PmsGuiaAcceso $acceso): self
    {
        $this->accesoParaCliente = $acceso;
        return $this;
    }

    /**
     * Estado del acceso, solo para que la UI elija el aviso de cabecera. La
     * decisión de qué se ve ya está tomada: lo que no se puede ver, no viene.
     *
     * @return array{estado: string, liberaEn: ?string}
     */
    #[Groups(['pax_guia:read'])]
    #[SerializedName('acceso')]
    public function getAccesoParaCliente(): array
    {
        return [
            'estado'   => $this->accesoParaCliente?->estado->value ?? PmsGuiaAccesoEstado::Publico->value,
            'liberaEn' => $this->accesoParaCliente?->liberaEn?->format(DATE_ATOM),
        ];
    }

    // --- SETTERS Y LÓGICA INTERNA ---

    public function setUnidad(?PmsUnidad $unidad): self { $this->unidad = $unidad; return $this; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    /**
     * @param list<array{language?: string, content?: string|null}> $titulo
     */
    public function setTitulo(array $titulo): self
    {
        $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this;
    }

    /**
     * @return Collection<int, PmsGuiaHasSeccion>
     */
    public function getGuiaHasSecciones(): Collection { return $this->guiaHasSecciones; }
    public function addGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self { if (!$this->guiaHasSecciones->contains($guiaHasSeccion)) { $this->guiaHasSecciones->add($guiaHasSeccion); $guiaHasSeccion->setGuia($this); } return $this; }
    public function removeGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self { if ($this->guiaHasSecciones->removeElement($guiaHasSeccion)) { if ($guiaHasSeccion->getGuia() === $this) { $guiaHasSeccion->setGuia(null); } } return $this; }

    public function __toString(): string
    {
        $nombreUnidad = $this->unidad?->getNombre();

        // ⚠️ `titulo` es una LISTA de pares `{language, content}` —lo impone
        // `MaestroIdioma::normalizarParaDB()`—, no un mapa por idioma. El `$this->titulo['es']`
        // que había aquí no encontraba nunca nada, así que toda guía se mostraba en el panel con
        // el nombre de la unidad en vez de con su título. Se recorre igual que en `validate()`.
        $tituloGuia = null;
        foreach ($this->titulo as $item) {
            if (($item['language'] ?? null) === 'es' && !empty(trim((string) ($item['content'] ?? '')))) {
                $tituloGuia = $item['content'];
                break;
            }
        }
        return $tituloGuia ? "$tituloGuia ($nombreUnidad)" : ($nombreUnidad ?? 'Guía UUID ' . $this->getId());
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        $espanolEncontrado = false;

        // Verificamos que no esté vacío el campo principal
        if (!empty($this->titulo) && is_iterable($this->titulo)) {

            foreach ($this->titulo as $item) {
                // 1. Accedemos como Array Asociativo: $item['language']
                // Usamos operador null coalescing (??) por seguridad
                $lang = $item['language'] ?? null;
                $content = $item['content'] ?? null;

                // 2. Validamos si es español y tiene contenido real
                if ($lang === 'es' && !empty(trim($content))) {
                    $espanolEncontrado = true;
                    break;
                }
            }
        }

        if (!$espanolEncontrado) {
            $context->buildViolation('El título en español (es) es obligatorio.')
                ->atPath('titulo')
                ->addViolation();
        }
    }

    public function getVirtualSecciones(): string { return ''; }
}