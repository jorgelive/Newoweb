<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Entity\Maestro\MaestroPais;
use App\Cotizacion\Enum\AlcanceDeVistaEnum;
use App\Cotizacion\State\CotizacionFilepasajeroProcessor;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;
use App\Service\Phone\PhoneSanitizer;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'CotizacionFilepasajero',
    operations: [
        new Post(
            // ⚠️ Sin grupos de normalización, la respuesta arrastra el grafo entero y el
            // pasajero vuelve sobre sí mismo: pertenencia → grupo → miembros → la MISMA
            // pertenencia. El serializador corta con una `CircularReferenceException`, que sale
            // como 500 al guardar. Pasó el 24/08/2026 al asignarle un vuelo a un pasajero.
            normalizationContext: ['groups' => ['file:item:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['file:write']],
            processor: CotizacionFilepasajeroProcessor::class,
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear pasajeros.'
        ),
        new Put(
            // Grupos de normalización por lo mismo que arriba: el círculo de las pertenencias.
            normalizationContext: ['groups' => ['file:item:read', 'timestamp:read']],
            // ⚠️ `standard_put` desactivado a propósito. Con el estándar —el de serie desde API
            // Platform 4—, el PUT deserializa sobre un objeto NUEVO y el processor de Doctrine
            // copia sus propiedades por reflexión sobre la entidad cargada, **colecciones
            // incluidas**. Eso cambia la `PersistentCollection` entera por otra: `orphanRemoval`
            // borra las filas de antes, se insertan las nuevas, y como los INSERT van primero se
            // vuelve al 1062 de `(pasajero, tipo)` por otra puerta —justo la que
            // {@see CotizacionFilepasajeroProcessor} no puede ver, porque ahí no hay foto contra
            // la que comparar—. Nadie usa este PUT (el front escribe con POST y PATCH), pero
            // dejarlo armado es dejar una trampa cargada.
            extraProperties: ['standard_put' => false],
            denormalizationContext: ['groups' => ['file:write']],
            processor: CotizacionFilepasajeroProcessor::class,
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar pasajeros.'
        ),
        new Patch(
            // Grupos de normalización por lo mismo que arriba: el círculo de las pertenencias.
            normalizationContext: ['groups' => ['file:item:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['file:write']],
            processor: CotizacionFilepasajeroProcessor::class,
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar pasajeros.'
        ),
        new Delete(
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
            securityMessage: 'No tienes permiso para eliminar pasajeros.'
        )
    ],
    routePrefix: '/sales'
)]
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_file_pasajero')]
#[ORM\HasLifecycleCallbacks]
class CotizacionFilepasajero
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre = null;

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $apellido = null;

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroPais $pais = null;


    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    /** Nulable: un padrón real llega con huecos —131 de 133 en el de Punta Cana— y bloquear la
     *  carga entera por dos celdas vacías es desproporcionado. El tipo en PHP ya lo toleraba. */
    #[ORM\Column(type: 'string', length: 1, nullable: true, enumType: SexoEnum::class)]
    private ?SexoEnum $sexo = null;

    // 🔥 Reemplazado por Enum
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $fechanacimiento = null;

    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'filepasajeros')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFile $file = null;

    /**
     * Los documentos que identifican a esta persona: su DNI, su pasaporte, su carné.
     *
     * Sustituye a `tipodocumento` + `numerodocumento`, que sólo admitían uno y sin vencimiento.
     * Ver el docblock de {@see CotizacionPasajeroIdentificacion} para el porqué.
     *
     * ⚠️ **`fetch: 'EAGER'` y tiene que seguir así.** Con `LAZY` esto es un N+1 clásico: una
     * consulta por pasajero al pintar el manifiesto. Medido sobre un expediente de 142 pasajeros
     * —el tamaño real de un grupo de colegio, no el de dos personas con el que se diseñó—:
     *
     *     LAZY    143 consultas · 70 ms
     *     EAGER     3 consultas · 44 ms
     *
     * Doctrine agrupa el EAGER de una colección en un solo `WHERE pasajero_id IN (…)`. Con dos
     * pasajeros la diferencia es invisible, que es exactamente por qué esto hay que dejarlo
     * escrito: nadie va a notar la regresión hasta que un grupo grande la note entero.
     *
     * @var Collection<int, CotizacionPasajeroIdentificacion>
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\OneToMany(mappedBy: 'pasajero', targetEntity: CotizacionPasajeroIdentificacion::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EAGER')]
    private Collection $identificaciones;

    /**
     * A qué subgrupos pertenece: su salón, su grupo, su habitación, sus reservas aéreas.
     *
     * ⚠️ `EAGER` por lo mismo que las identificaciones: con `LAZY` esto es una consulta por
     * pasajero al pintar el manifiesto, y con 140 personas eso son 140 consultas que con dos no
     * se notan. Medido en `docs/Cotizaciones.md` §6.l.
     *
     * @var Collection<int, CotizacionPasajeroGrupo>
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\OneToMany(mappedBy: 'pasajero', targetEntity: CotizacionPasajeroGrupo::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EAGER')]
    private Collection $pertenencias;

    /**
     * Qué es dentro del grupo, y de ahí qué ve y quién le ve.
     *
     * Nulo se trata como el caso más restrictivo —sólo lo suyo, y visible— porque un tipo sin
     * poner no puede conceder nada. Ver {@see PasajeroTipoEnum}.
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: PasajeroTipoEnum::class)]
    private ?PasajeroTipoEnum $tipo = null;

    /**
     * Su teléfono, no el del expediente.
     *
     * `CotizacionFile` ya tiene uno, pero es el del contacto principal. En un grupo de 133 personas
     * hay 133 familias a las que llamar cuando falta un pasaporte, y el padrón real los trae todos.
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $telefono = null;

    /** Texto libre del padrón: «FALTA PASAPORTE», «reemplaza a…». */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observaciones = null;

    public function __construct()
    {
        $this->initializeId();
        $this->identificaciones = new ArrayCollection();
        $this->pertenencias = new ArrayCollection();
    }

    public function __toString(): string
    {
        $nombreCompleto = trim(($this->nombre ?? '') . ' ' . ($this->apellido ?? ''));
        return $nombreCompleto !== '' ? $nombreCompleto : 'Pasajero sin nombre';
    }

    /* ======================================================
     * LÓGICA DE NEGOCIO (Migrada del Legacy)
     * ====================================================== */

    /**
     * Obtiene el primer bloque del apellido.
     */
    #[Groups(['file:item:read'])]
    public function getApellidoPaterno(): ?string
    {
        if (empty($this->apellido)) {
            return null;
        }
        $apellidosArray = explode(' ', $this->apellido, 2);
        // `explode()` nunca devuelve vacío y el apellido ya se comprobó arriba.
        return $apellidosArray[0];
    }

    /**
     * Obtiene el segundo bloque del apellido.
     */
    #[Groups(['file:item:read'])]
    public function getApellidoMaterno(): ?string
    {
        if (empty($this->apellido)) {
            return null;
        }
        $apellidosArray = explode(' ', $this->apellido, 2);
        return $apellidosArray[1] ?? null;
    }

    /**
     * Calcula la edad actual basada en la fecha de nacimiento.
     */
    #[Groups(['file:item:read'])]
    public function getEdad(): ?int
    {
        if (!$this->fechanacimiento) {
            return null;
        }
        $hoy = new DateTimeImmutable('today');
        return $hoy->diff($this->fechanacimiento)->y;
    }

    /**
     * Devuelve el código de tipo de pasajero según las reglas de PeruRail.
     * 1 = Adulto (>=12), 2 = Niño (<12).
     */
    #[Groups(['file:item:read'])]
    public function getTipopaxperurail(): ?int
    {
        $edad = $this->getEdad();
        if ($edad === null) {
            return null;
        }
        return $edad >= 12 ? 1 : 2;
    }

    /**
     * Devuelve la categoría tarifaria según la Dirección Desconcentrada de Cultura (DDC).
     */
    #[Groups(['file:item:read'])]
    public function getCategoriaddc(): ?int
    {
        $edad = $this->getEdad();
        if ($edad === null) {
            return null;
        }
        if ($edad >= 18) {
            return 1;
        }
        if ($edad >= 13 && $edad <= 17) {
            return 2;
        }
        if ($edad >= 3 && $edad <= 12) {
            return 7;
        }
        return 0; // Infante u otro
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getApellido(): ?string { return $this->apellido; }
    public function setApellido(?string $apellido): self { $this->apellido = $apellido; return $this; }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getSexo(): ?SexoEnum { return $this->sexo; }
    public function setSexo(?SexoEnum $sexo): self { $this->sexo = $sexo; return $this; }


    public function getFechanacimiento(): ?DateTimeInterface { return $this->fechanacimiento; }
    public function setFechanacimiento(?DateTimeInterface $fechanacimiento): self { $this->fechanacimiento = $fechanacimiento; return $this; }


    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    /** @return Collection<int, CotizacionPasajeroIdentificacion> */
    public function getIdentificaciones(): Collection { return $this->identificaciones; }

    public function addIdentificacion(CotizacionPasajeroIdentificacion $identificacion): self
    {
        if (!$this->identificaciones->contains($identificacion)) {
            $this->identificaciones->add($identificacion);
            $identificacion->setPasajero($this);
        }

        return $this;
    }

    public function removeIdentificacion(CotizacionPasajeroIdentificacion $identificacion): self
    {
        if ($this->identificaciones->removeElement($identificacion)) {
            if ($identificacion->getPasajero() === $this) {
                $identificacion->setPasajero(null);
            }
        }

        return $this;
    }

    /** El documento de un tipo concreto, o `null`. */
    public function identificacionDe(DocumentoTipoEnum $tipo): ?CotizacionPasajeroIdentificacion
    {
        foreach ($this->identificaciones as $identificacion) {
            if ($identificacion->getTipo() === $tipo) {
                return $identificacion;
            }
        }

        return null;
    }

    /**
     * Los documentos que NO sirven para viajar en una fecha.
     *
     * ⚠️ Los que no tienen fecha cargada **no salen aquí**, y por eso quien llame tiene que
     * contarlos aparte: en el padrón real de Punta Cana había 22 personas sin vencimiento de DNI, y
     * tratarlas como vigentes es lo que dejó pasar once documentos caducados en una lista que se
     * daba por revisada. «Sin comprobar» no es «vigente».
     *
     * @return list<CotizacionPasajeroIdentificacion>
     */
    public function identificacionesVencidasAl(\DateTimeInterface $fecha): array
    {
        return array_values(array_filter(
            $this->identificaciones->toArray(),
            static fn (CotizacionPasajeroIdentificacion $i): bool => $i->estaVigenteEl($fecha) === false,
        ));
    }

    /** @return list<CotizacionPasajeroIdentificacion> */
    public function identificacionesSinComprobar(): array
    {
        return array_values(array_filter(
            $this->identificaciones->toArray(),
            static fn (CotizacionPasajeroIdentificacion $i): bool => $i->getVencimiento() === null,
        ));
    }

    /** @return Collection<int, CotizacionPasajeroGrupo> */
    public function getPertenencias(): Collection { return $this->pertenencias; }

    public function addPertenencia(CotizacionPasajeroGrupo $pertenencia): self
    {
        if (!$this->pertenencias->contains($pertenencia)) {
            $this->pertenencias->add($pertenencia);
            $pertenencia->setPasajero($this);
        }

        return $this;
    }

    public function removePertenencia(CotizacionPasajeroGrupo $pertenencia): self
    {
        if ($this->pertenencias->removeElement($pertenencia) && $pertenencia->getPasajero() === $this) {
            $pertenencia->setPasajero(null);
        }

        return $this;
    }

    /**
     * Sus grupos, sin la capa de pertenencia.
     *
     * @return list<CotizacionFileGrupo>
     */
    public function grupos(): array
    {
        return array_values(array_filter(array_map(
            static fn (CotizacionPasajeroGrupo $p): ?CotizacionFileGrupo => $p->getGrupo(),
            $this->pertenencias->toArray(),
        )));
    }

    public function getTipo(): ?PasajeroTipoEnum { return $this->tipo; }
    public function setTipo(?PasajeroTipoEnum $v): self { $this->tipo = $v; return $this; }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $v): self { $this->telefono = $v !== null ? (trim($v) ?: null) : null; return $this; }

    public function getObservaciones(): ?string { return $this->observaciones; }
    public function setObservaciones(?string $v): self { $this->observaciones = $v !== null ? (trim($v) ?: null) : null; return $this; }

    /**
     * Hasta dónde llega lo que ve.
     *
     * Sin tipo, lo mínimo. Un dato que falta no puede conceder permisos — y en el padrón real hay
     * gente sin tipo.
     */
    #[Groups(['file:item:read'])]
    public function getAlcanceDeVista(): AlcanceDeVistaEnum
    {
        return $this->tipo?->alcance() ?? AlcanceDeVistaEnum::SOLO_YO;
    }

    /**
     * ¿Aparece en las listas que ven los demás?
     *
     * Sin tipo, sí: esconder a alguien por descuido es peor que enseñarlo, porque deja huecos que
     * nadie sabe explicar. Ocultar es una decisión, y las decisiones se escriben.
     */
    #[Groups(['file:item:read'])]
    public function isExpuesto(): bool
    {
        return $this->tipo?->esExpuesto() ?? true;
    }

    /**
     * El teléfono se guarda en E.164 sin el «+», como en todo el sistema.
     *
     * Mismo `PhoneSanitizer` que usa {@see CotizacionFile::sanitizarCampos()}: si cada sitio
     * limpiara a su manera, el mismo número quedaría escrito de dos formas y buscar por teléfono
     * dejaría de encontrar a nadie.
     *
     * ⚠️ Con una diferencia a favor: el expediente asume `'PE'` porque no tiene nada mejor, y el
     * pasajero **sí sabe su país**. Un número mexicano de un invitado se interpreta como mexicano
     * en vez de pegarle un +51.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function sanitizarTelefono(): void
    {
        if ($this->telefono === null || $this->telefono === '') {
            return;
        }

        $limpio = (new PhoneSanitizer())->cleanPhoneNumber($this->telefono, $this->pais?->getId() ?? 'PE');
        $this->telefono = $limpio !== '' ? $limpio : null;
    }
}
