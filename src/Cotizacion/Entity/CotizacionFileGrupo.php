<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Security\Roles;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un subgrupo dentro de un expediente: el salón B, el grupo 5, la habitación HA13, el vuelo JA2CWN.
 *
 * ## Cuelga del EXPEDIENTE, y esto no es un detalle
 *
 * El «Grupo 5» de Punta Cana no es el «Grupo 5» de otro viaje. De ahí sale la unicidad
 * `(file, tipo, clave)`, que además es lo que hace que **reimportar el padrón corregido no
 * duplique nada** — y ese Excel se vuelve a subir varias veces antes de un viaje.
 *
 * ## El código es DATO; el papel es archivo
 *
 * `clave` guarda el PNR (`JA2CWN`), no el PDF. Si el código viviera dentro del archivo no se
 * podría buscar por él, ordenarlo, imprimirlo en la orden ni pegarlo en la web de la aerolínea,
 * que es todo lo que se hace con un localizador. El namelist se sube aparte y cuelga de este
 * grupo — ver la clave `grupo` de {@see CotizacionFilearchivo}.
 *
 * El propio padrón describe el orden: *«la asignación individual se define al cargar los
 * namelists»*. El archivo no **es** la pertenencia: la **define**, y después queda de respaldo.
 */
#[ApiResource(
    shortName: 'CotizacionFileGrupo',
    operations: [
        new Post(
            // ⚠️ Por lo mismo que en CotizacionFilepasajero: sin grupos, la respuesta va
            // grupo → miembros → pertenencia → el MISMO grupo y el serializador corta con una
            // `CircularReferenceException`. Aquí se ve al renombrar un grupo que ya tiene gente.
            normalizationContext: ['groups' => ['file:item:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['file:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear subgrupos.'
        ),
        new Patch(
            // Grupos de normalización por lo mismo que arriba: el círculo de las pertenencias.
            normalizationContext: ['groups' => ['file:item:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar subgrupos.'
        ),
        // Borrar un grupo se lleva sus pertenencias por CASCADE, no a los pasajeros: perder el
        // «Salón B» no es perder a nadie, sólo deja de haber salón.
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar subgrupos.'
        ),
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file_grupo')]
// ⚠️ El `subeje` entra en la unicidad: `#Vuelo Ida` y `#Vuelo Retorno` con el mismo localizador
// —una aerolínea reutiliza códigos entre tramos— son dos grupos distintos. Sin él, el segundo se
// fundiría con el primero en silencio.
#[ORM\UniqueConstraint(name: 'uniq_file_grupo_tipo_clave', columns: ['file_id', 'tipo', 'subeje', 'clave'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionFileGrupo
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'grupos')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    #[Assert\NotNull(message: 'Indica en qué eje agrupa.')]
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    // 40 y no 20: `reserva_aerea_internacional` son 27 caracteres. Con el largo viejo el eje nuevo
    // no cabía, y MySQL en modo no estricto lo habría TRUNCADO en vez de fallar.
    #[ORM\Column(type: 'string', length: 40, enumType: GrupoTipoEnum::class)]
    private ?GrupoTipoEnum $tipo = null;

    /**
     * La etiqueta que subdivide el eje: «Nacional», «Internacional», «Cusco-Puno», «Retorno».
     *
     * ⚠️ **Texto libre, y ahí está la gracia.** El tramo estuvo modelado como dos casos del enum
     * —`RESERVA_AEREA_NACIONAL` e `_INTERNACIONAL`— y eso convertía una etiqueta en un tipo: un
     * multitramo Lima→Cusco→Puno→Lima habría pedido un `case` por vuelo, o sea **un despliegue
     * para apuntar un billete**.
     *
     * Cadena vacía significa «el eje entero, sin subdividir»: un viaje con un solo vuelo, y todos
     * los ejes que no admiten tramo ({@see GrupoTipoEnum::admiteSubeje()}).
     *
     * ⚠️ **Vacía, NO nula, y es lo que sostiene la unicidad.** En InnoDB un índice único admite
     * cuantos `NULL` quiera, así que con la columna nullable **todo grupo sin tramo —habitaciones,
     * grupos, servicios: casi todo lo que existe— se quedaba fuera de
     * `uniq_file_grupo_tipo_clave`**. Un doble POST creaba dos «HA13» sin que nada lo impidiera,
     * justo la protección que había antes de partir esta columna. Con cadena vacía, la fila entra
     * en el índice.
     *
     * Entra por la cabecera de la columna del padrón: `#Vuelo Nacional` → `subeje = 'Nacional'`.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 60, options: ['default' => ''])]
    private string $subeje = '';

    /**
     * El valor dentro del eje: `B`, `5`, `HA13`, `JA2CWN`.
     *
     * Se normaliza al guardar —sin espacios, en mayúsculas— porque de aquí depende la unicidad: sin
     * eso, «ha13» y «HA13 » serían dos habitaciones distintas y el cruce posterior no encontraría a
     * nadie.
     */
    #[Assert\NotBlank(message: 'El grupo necesita un valor: «B», «5», «HA13»…')]
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 60)]
    private ?string $clave = null;

    /**
     * El rótulo CORTO, el que cabe al lado de la clave: «ARAJET», «DOBLE», «JetSmart».
     *
     * Un localizador no dice nada: `IFBI5Q` es Arajet y `Y9KZ7J` es JetSmart, y elegir entre veinte
     * a ojo es adivinar. La píldora del pasajero pinta `clave` + `nombre` en la misma línea, así
     * que aquí caben tres palabras, no una frase. Lo que no quepa va a {@see self::$detalle}.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    /**
     * El detalle largo, opcional y de varias líneas. No cabe en una píldora y no se pinta en una.
     *
     * ```
     * Ida DM6771 · LIM 18/09/2026 03:00 → PUJ 18/09/2026 09:19
     * Retorno DM6770 · PUJ 22/09/2026 20:22 → LIM 23/09/2026 00:30
     * ```
     *
     * ⚠️ **Va aparte de `nombre` y no concatenado, porque son dos lecturas distintas.** El corto
     * sirve para ELEGIR entre veinte códigos de un vistazo; el largo, para COMPROBAR un horario
     * cuando ya sabes cuál miras. Metidos en el mismo campo, el corto deja de servir para lo
     * primero: la píldora se convierte en un párrafo.
     *
     * `text` y no `string`: son dos líneas hoy y pueden ser cuatro con escalas.
     */
    /**
     * ¿La reserva está emitida, o pagada y esperando billete?
     *
     * ⚠️ Va aquí y no en el vuelo, aunque al principio lo puse allí. El `H2 5002` es un vuelo
     * perfectamente real y emitido para quien tenga su billete; lo que está pagado sin emitir
     * son **estos 44 billetes**. Un mismo vuelo puede llevar reservas emitidas y sin emitir a
     * la vez, así que en el vuelo la bandera no significaba nada.
     *
     * Mientras es `false`, la `clave` de arriba es un localizador **provisional** —«AAAAA»— y
     * eso no se distingue mirándolo de uno real. De ahí que haga falta guardarlo: son 88
     * personas a las que hay que perseguir un billete antes del 17/09.
     *
     * Sólo tiene sentido en `reserva_aerea`; en una habitación o un salón se queda en `true`
     * y nadie lo mira.
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $emitido = true;

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $detalle = null;

    /**
     * ⚠️ **NO se serializa, y no es un olvido.**
     *
     * El pasajero expone sus `pertenencias`, y cada una expone su `grupo`. Si el grupo expusiera
     * de vuelta sus miembros el ciclo se cierra —pasajero → pertenencia → grupo → miembro → la
     * MISMA pertenencia— y el serializador aborta con «circular reference».
     *
     * Y no salta al programarlo: con los grupos vacíos la colección no tiene por dónde volver, así
     * que pasa las pruebas, pasa el despliegue, y revienta el día que alguien carga un padrón. Fue
     * exactamente lo que ocurrió con los 133 de Punta Cana.
     *
     * Lo que la pantalla necesita del grupo es **cuántos son**, y eso lo da
     * {@see self::getTotalMiembros()} en un `SELECT COUNT(*)` gracias al `EXTRA_LAZY`. Los
     * miembros uno a uno los tiene ya el otro lado.
     *
     * @var Collection<int, CotizacionPasajeroGrupo>
     */
    #[ORM\OneToMany(mappedBy: 'grupo', targetEntity: CotizacionPasajeroGrupo::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $miembros;

    public function __construct()
    {
        $this->initializeId();
        $this->miembros = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getEtiqueta();
    }

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['file:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;

        return $this;
    }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $v): self { $this->file = $v; return $this; }

    public function getTipo(): ?GrupoTipoEnum { return $this->tipo; }
    public function setTipo(?GrupoTipoEnum $v): self { $this->tipo = $v; return $this; }

    public function getClave(): ?string { return $this->clave; }

    public function setClave(?string $v): self
    {
        $this->clave = $v !== null ? (mb_strtoupper(trim($v)) ?: null) : null;

        return $this;
    }

    public function getSubeje(): string { return $this->subeje; }
    public function setSubeje(?string $v): self { $this->subeje = trim($v ?? ''); return $this; }

    /** «Vuelo Nacional», «Habitación». Lo que va en la cabecera de la columna y en la pantalla. */
    #[Groups(['file:item:read', 'pax_file:read'])]
    public function getEtiquetaDeEje(): string
    {
        return trim(($this->tipo?->label() ?? '').' '.$this->subeje);
    }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $v): self { $this->nombre = $v !== null ? (trim($v) ?: null) : null; return $this; }

    public function isEmitido(): bool { return $this->emitido; }
    public function setEmitido(bool $emitido): self { $this->emitido = $emitido; return $this; }

    public function getDetalle(): ?string { return $this->detalle; }
    public function setDetalle(?string $v): self { $this->detalle = $v !== null ? (trim($v) ?: null) : null; return $this; }

    /** @return Collection<int, CotizacionPasajeroGrupo> */
    public function getMiembros(): Collection { return $this->miembros; }

    public function addMiembro(CotizacionPasajeroGrupo $miembro): self
    {
        if (!$this->miembros->contains($miembro)) {
            $this->miembros->add($miembro);
            $miembro->setGrupo($this);
        }

        return $this;
    }

    public function removeMiembro(CotizacionPasajeroGrupo $miembro): self
    {
        if ($this->miembros->removeElement($miembro) && $miembro->getGrupo() === $this) {
            $miembro->setGrupo(null);
        }

        return $this;
    }

    /** Cómo se llama esto en pantalla. */
    #[Groups(['file:item:read', 'pax_file:read'])]
    public function getEtiqueta(): string
    {
        return $this->nombre ?? sprintf('%s %s', $this->tipo?->label() ?? 'Grupo', $this->clave ?? '—');
    }

    #[Groups(['file:item:read'])]
    public function getTotalMiembros(): int { return $this->miembros->count(); }
}
