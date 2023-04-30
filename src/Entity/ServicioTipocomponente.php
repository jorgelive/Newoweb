<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * ServicioTipocomponente
 *
 * @ORM\Table(name="ser_tipocomponente")
 * @ORM\Entity
 */
class ServicioTipocomponente
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=50)
     */
    private $nombre;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $dependeduracion;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $agendable;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=false, options={"default":1})
     */
    protected $prioridadparaproveedor;

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
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }


    public function getId(): int
    {
        return $this->id;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setPrioridadparaproveedor(?int $prioridadparaproveedor): self
    {
        $this->prioridadparaproveedor = $prioridadparaproveedor;

        return $this;
    }

    public function getPrioridadparaproveedor(): ?int
    {
        return $this->prioridadparaproveedor;
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

    public function setDependeduracion(?bool $dependeduracion): self
    {
        $this->dependeduracion = $dependeduracion;
    
        return $this;
    }

    public function isDependeduracion(): ?bool
    {
        return $this->dependeduracion;
    }

    public function setAgendable(?bool $agendable): self
    {
        $this->agendable = $agendable;

        return $this;
    }

    public function isAgendable(): ?bool
    {
        return $this->agendable;
    }

}
