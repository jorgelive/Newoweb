<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * ServicioProvider
 *
 * @ORM\Table(name="ser_provider")
 * @ORM\Entity
 */
class ServicioProvider
{

    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=30)
     */
    private ?string $nombre = null;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private ?string $nombremostrar = null;

    /**
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $direccion = null;

    /**
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private ?string $telefono = null;

    /**
     * @ORM\Column(type="string", length=40, nullable=true)
     */
    private ?string $email = null;

    /**
     * @ORM\OneToMany(targetEntity="ServicioTarifa", mappedBy="provider", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"nombre" = "ASC"})
     */
    private Collection $tarifas;

    /**
     * @ORM\OneToMany(targetEntity="ServicioProvidermedio", mappedBy="provider", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"nombre" = "ASC"})
     */
    private Collection $providermedios;

    /**

     * @ORM\OneToMany(targetEntity="CotizacionCottarifa", mappedBy="provider", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private Collection $cottarifas;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $creado;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $modificado;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->tarifas = new ArrayCollection();
        $this->providermedios = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setNombre(string $nombre): self
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function setNombremostrar(string $nombremostrar): self
    {
        $this->nombremostrar = $nombremostrar;

        return $this;
    }

    public function getNombremostrar(): string
    {
        return $this->nombremostrar;
    }

    public function setDireccion(?string $direccion): self
    {
        $this->direccion = $direccion;

        return $this;
    }

    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setCreado(?\DateTime $creado): self
    {
        $this->creado = $creado;
    
        return $this;
    }

    public function getCreado(): ?\DateTime
    {
        return $this->creado;
    }

    public function setModificado(?\DateTime $modificado): self
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    public function getModificado(): ?\DateTime
    {
        return $this->modificado;
    }

    public function addTarifa(?ServicioTarifa $tarifa): self
    {
        $tarifa->setProvider($this);

        $this->tarifas[] = $tarifa;
    
        return $this;
    }

    public function removeTarifa(?ServicioTarifa $tarifa)
    {
        $this->tarifas->removeElement($tarifa);
    }

    public function getTarifas(): ?Collection
    {
        return $this->tarifas;
    }

    public function addCottarifa(?CotizacionCottarifa $cottarifa): self
    {
        $cottarifa->setProvider($this);

        $this->cottarifas[] = $cottarifa;

        return $this;
    }

    public function removeCottarifa(?CotizacionCottarifa $cottarifa)
    {
        $this->cottarifas->removeElement($cottarifa);
    }

    public function getCottarifas(): ?Collection
    {
        return $this->cottarifas;
    }

    public function getProvidermedios(): Collection
    {
        return $this->providermedios;
    }

    public function addProvidermedio(ServicioProvidermedio $providermedio): self
    {
        $providermedio->setProvider($this);

        $this->providermedios[] = $providermedio;

        return $this;
    }

    public function removeProvidermedio(ServicioProvidermedio $providermedio): self
    {
        if($this->providermedios->removeElement($providermedio)) {

            if($providermedio->getProvider() === $this) {
                $providermedio->setProvider(null);
            }
        }

        return $this;
    }


}
