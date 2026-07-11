<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use App\Api\Provider\Cotizacion\CotizacionFilePublicProvider;
use App\Entity\Maestro\MaestroIdioma;
use App\Entity\Maestro\MaestroPais;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\LocatorTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * El Expediente raíz. Agrupa todas las propuestas comerciales de un cliente o grupo.
 */
#[ApiResource(
    shortName: 'CotizacionFile',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['file:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        new Get(
            normalizationContext: ['groups' => ['file:read', 'file:item:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')"
        ),
        new Get(
            uriTemplate: '/client/cotizacion/cotizacion_file/{localizador}',
            uriVariables: [
                'localizador' => new Link(fromClass: CotizacionFile::class, identifiers: ['localizador']),
            ],
            normalizationContext: ['groups' => ['pax_cotizacion:read']],
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
#[ORM\HasLifecycleCallbacks]
class CotizacionFile
{
    use IdTrait;
    use TimestampTrait;
    use LocatorTrait;

    #[Groups(['file:read', 'file:item:read', 'file:write', 'pax_cotizacion:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreGrupo = null;

    #[Groups(['file:read', 'file:item:read', 'file:write', 'pax_cotizacion:read'])]
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
    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroIdioma $idioma = null;

    #[Groups(['file:read', 'file:item:read', 'file:write'])]
    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'abierto'])]
    private string $estado = 'abierto';

    /**
     * @var Collection<int, Cotizacion>
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read', 'file:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: Cotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['version' => 'DESC'])]
    private Collection $cotizaciones;

    /**
     * @var Collection<int, CotizacionFilepasajero>
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read', 'pax_cotizacion:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFilepasajero::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $filepasajeros;

    /**
     * @var Collection<int, CotizacionFiledocumento>
     */
    #[ApiProperty(fetchEager: false)]
    #[Groups(['file:item:read'])]
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFiledocumento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $filedocumentos;

    public function __construct()
    {
        $this->initializeId();
        $this->initializeLocator();
        $this->cotizaciones = new ArrayCollection();
        $this->filepasajeros = new ArrayCollection();
        $this->filedocumentos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->nombreGrupo ?? 'File sin nombre';
    }

    /* ======================================================
     * GETTERS Y SETTERS
     * ====================================================== */

    #[Groups(['file:read', 'file:item:read', 'pax_cotizacion:read'])]
    #[SerializedName('localizador')]
    public function getLocalizadorPublico(): ?string
    {
        // Se mapea con la propiedad $this->localizador del Trait
        return $this->localizador;
    }

    /**
     * Cotización activa expuesta al cliente vía el visor público.
     * Se resuelve aquí (no en el provider) para que la serialización
     * la incluya embebida en la misma respuesta, sin exponer un id
     * de Cotizacion consultable de forma independiente.
     */
    #[Groups(['pax_cotizacion:read'])]
    public function getCotizacionActiva(): ?Cotizacion
    {
        return $this->cotizaciones
            ->filter(fn(Cotizacion $c) => $c->getEstado()->esPublico())
            ->first() ?: null;
    }

    /**
     * Documentos visibles para el cliente en el visor público.
     * Filtra por ArchivoTipoEnum::esPublico() en vez de una lista de
     * strings hardcodeada, para mantener la regla en un solo sitio.
     */
    #[Groups(['pax_cotizacion:read'])]
    public function getDocumentosParaCliente(): array
    {
        return $this->filedocumentos->filter(
            fn(CotizacionFiledocumento $doc) => $doc->getTipodocumento()?->esPublico() === true
        )->getValues();
    }

    public function getPais(): ?MaestroPais { return $this->pais; }
    public function setPais(?MaestroPais $pais): self { $this->pais = $pais; return $this; }

    public function getIdioma(): ?MaestroIdioma { return $this->idioma; }
    public function setIdioma(?MaestroIdioma $idioma): self { $this->idioma = $idioma; return $this; }

    public function getNombreGrupo(): ?string { return $this->nombreGrupo; }
    public function setNombreGrupo(string $nombreGrupo): self { $this->nombreGrupo = $nombreGrupo; return $this; }

    public function getPasajeroPrincipal(): ?string { return $this->pasajeroPrincipal; }
    public function setPasajeroPrincipal(?string $pasajeroPrincipal): self { $this->pasajeroPrincipal = $pasajeroPrincipal; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getTelefono(): ?string { return $this->telefono; }
    public function setTelefono(?string $telefono): self { $this->telefono = $telefono; return $this; }

    public function getEstado(): string { return $this->estado; }
    public function setEstado(string $estado): self { $this->estado = $estado; return $this; }

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

    public function getFiledocumentos(): Collection { return $this->filedocumentos; }
    public function addFiledocumento(CotizacionFiledocumento $filedocumento): self
    {
        if (!$this->filedocumentos->contains($filedocumento)) {
            $this->filedocumentos->add($filedocumento);
            $filedocumento->setFile($this);
        }
        return $this;
    }
    public function removeFiledocumento(CotizacionFiledocumento $filedocumento): self
    {
        if ($this->filedocumentos->removeElement($filedocumento)) {
            if ($filedocumento->getFile() === $this) { $filedocumento->setFile(null); }
        }
        return $this;
    }
}