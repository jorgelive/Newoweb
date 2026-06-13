<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Entidad de Catálogo Maestro que representa un Proveedor logístico u hotelero.
 * Expuesto en API Platform en modo de solo lectura para ser consumido por el motor de Vue.
 */
#[ApiResource(
    shortName: 'Proveedor',
    operations: [
        new GetCollection(
            uriTemplate: '/proveedores',
            normalizationContext: ['groups' => ['proveedor:read']]
        ),
        new Get(
            uriTemplate: '/proveedores/{id}',
            normalizationContext: ['groups' => ['proveedor:read', 'proveedor:item:read']]
        )
    ],
    routePrefix: '/travel'
)]
#[ORM\Entity]
#[ORM\Table(name: 'travel_proveedor')]
#[ORM\HasLifecycleCallbacks]
class Proveedor
{
    use IdTrait;
    use TimestampTrait;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 150)]
    private ?string $nombreComercial = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $razonSocial = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $telefono = null;

    #[Groups(['proveedor:read', 'proveedor:item:read'])]
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $email = null;

    /**
     * @var Collection<int, ProveedorImagen>
     */
    #[Groups(['proveedor:item:read'])]
    #[ORM\OneToMany(
        mappedBy: 'proveedor',
        targetEntity: ProveedorImagen::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $proveedorImagenes;

    /**
     * Constructor de la entidad Proveedor.
     * Inicializa el identificador único UUIDv7 y la colección interna de imágenes.
     */
    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->proveedorImagenes = new ArrayCollection();
    }

    /**
     * Representación textual legible de la entidad para EasyAdmin.
     *
     * @return string Retorna el nombre comercial del proveedor o un marcador genérico.
     */
    public function __toString(): string
    {
        return $this->nombreComercial ?? 'Proveedor sin nombre';
    }

    /**
     * Obtiene el nombre comercial del proveedor.
     */
    public function getNombreComercial(): ?string
    {
        return $this->nombreComercial;
    }

    /**
     * Establece el nombre comercial del proveedor.
     */
    public function setNombreComercial(string $nombreComercial): self
    {
        $this->nombreComercial = $nombreComercial;
        return $this;
    }

    /**
     * Obtiene la razón social legal del proveedor.
     */
    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    /**
     * Establece la razón social legal del proveedor.
     */
    public function setRazonSocial(?string $razonSocial): self
    {
        $this->razonSocial = $razonSocial;
        return $this;
    }

    /**
     * Obtiene el número de teléfono principal del proveedor.
     */
    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    /**
     * Establece el número de teléfono principal del proveedor.
     */
    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;
        return $this;
    }

    /**
     * Obtiene el correo electrónico comercial del proveedor.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Establece el correo electrónico comercial del proveedor.
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Obtiene la colección completa de imágenes pertenecientes a la galería del proveedor.
     *
     * @return Collection<int, ProveedorImagen>
     */
    public function getProveedorImagenes(): Collection
    {
        return $this->proveedorImagenes;
    }

    /**
     * Añade un recurso de imagen a la galería garantizando la sincronización bidireccional de Doctrine.
     *
     * @param ProveedorImagen $proveedorImagen Instancia de la imagen a asociar.
     * @return $this
     */
    public function addProveedorImagen(ProveedorImagen $proveedorImagen): self
    {
        if (!$this->proveedorImagenes->contains($proveedorImagen)) {
            $this->proveedorImagenes->add($proveedorImagen);
            $proveedorImagen->setProveedor($this);
        }
        return $this;
    }

    /**
     * Remueve un recurso de imagen de la galería rompiendo el vínculo asociativo.
     *
     * @param ProveedorImagen $proveedorImagen Instancia de la imagen a desvincular.
     * @return $this
     */
    public function removeProveedorImagen(ProveedorImagen $proveedorImagen): self
    {
        if ($this->proveedorImagenes->removeElement($proveedorImagen)) {
            if ($proveedorImagen->getProveedor() === $this) {
                $proveedorImagen->setProveedor(null);
            }
        }
        return $this;
    }
}