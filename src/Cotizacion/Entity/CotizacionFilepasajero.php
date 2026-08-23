<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Entity\Maestro\MaestroPais;
use App\Enum\DocumentoTipoEnum;
use App\Enum\SexoEnum;
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
            denormalizationContext: ['groups' => ['file:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityPostDenormalizeMessage: 'No tienes permiso para crear pasajeros.'
        ),
        new Put(
            denormalizationContext: ['groups' => ['file:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
            securityMessage: 'No tienes permiso para editar pasajeros.'
        ),
        new Patch(
            denormalizationContext: ['groups' => ['file:write']],
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
    #[ORM\Column(type: 'string', length: 1, enumType: SexoEnum::class)]
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

    public function __construct()
    {
        $this->initializeId();
        $this->identificaciones = new ArrayCollection();
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
}
