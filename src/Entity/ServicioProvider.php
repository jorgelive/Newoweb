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
     * @var int
     *
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=30)
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     */
    private $nombremostrar;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $direccion;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private $telefono;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=40, nullable=true)
     */
    private $email;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioTarifa", mappedBy="provider", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"nombre" = "ASC"})
     */
    private $tarifas;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCottarifa", mappedBy="provider", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $cottarifas;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $modificado;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->tarifas = new ArrayCollection();
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

    public function getTarifas(): ?\Doctrine\Common\Collections\Collection
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

    public function getCottarifas(): ?\Doctrine\Common\Collections\Collection
    {
        return $this->cottarifas;
    }


}
