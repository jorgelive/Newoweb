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
        // ════════════════════════════════════════════════════════════════
        // CATÁLOGO PÚBLICO — por slug, .pe/casita/casita-1
        // Solo ítems marcados como públicos. No es la guía con las partes
        // tachadas: es un árbol distinto, construido a partir de otro filtro.
        // ════════════════════════════════════════════════════════════════
        new Get(
            uriTemplate: '/public/pax/pms/pms_guia/{establecimiento}/{unidad}',
            uriVariables: [
                'establecimiento' => new Link(fromClass: PmsEstablecimiento::class, identifiers: ['slug']),
                'unidad'          => new Link(fromClass: PmsUnidad::class, identifiers: ['slug']),
            ],
            normalizationContext: ['groups' => ['pax_catalogo:read']],
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PmsUnidadCatalogoProvider::class,
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

    #[ORM\Column(type: 'json')]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[Assert\NotNull]
    private array $titulo = [];

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

    #[Groups(['pax_guia:read', 'pax_catalogo:read'])]
    public function getTitulo(): array
    {
        return MaestroIdioma::ordenarParaFormulario($this->titulo);
    }

    /* ======================================================
     * VISTA PÚBLICA (pax) — las llenan los providers
     * ====================================================== */

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

    public function setTitulo(array $titulo): self
    {
        $this->titulo = MaestroIdioma::normalizarParaDB($titulo); return $this;
    }

    public function getGuiaHasSecciones(): Collection { return $this->guiaHasSecciones; }
    public function addGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self { if (!$this->guiaHasSecciones->contains($guiaHasSeccion)) { $this->guiaHasSecciones->add($guiaHasSeccion); $guiaHasSeccion->setGuia($this); } return $this; }
    public function removeGuiaHasSeccion(PmsGuiaHasSeccion $guiaHasSeccion): self { if ($this->guiaHasSecciones->removeElement($guiaHasSeccion)) { if ($guiaHasSeccion->getGuia() === $this) { $guiaHasSeccion->setGuia(null); } } return $this; }

    public function __toString(): string
    {
        $nombreUnidad = $this->unidad?->getNombre();
        $tituloGuia = $this->titulo['es'] ?? null;
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