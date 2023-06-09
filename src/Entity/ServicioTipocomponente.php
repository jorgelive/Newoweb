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

    public const DB_VALOR_TICKET = 1;
    public const DB_VALOR_GUIADO = 2;
    public const DB_VALOR_TRANSPORTE = 3;
    public const DB_VALOR_ALOJAMIENTO = 4;
    public const DB_VALOR_ALIMENTACION = 5;
    public const DB_VALOR_EXCURSION_POOL = 6;
    public const DB_VALOR_EXCURSION_PRIVADA = 7;
    public const DB_VALOR_PERSONAL_EXTRA = 8;
    public const DB_VALOR_EXTRAS = 9;
    public const DB_VALOR_VUELO = 10;

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=50)
     */
    private ?string $nombre = null;

    /**
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private ?bool $dependeduracion = false;

    /**
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private ?bool $agendable = false;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default":1})
     */
    protected ?int $prioridadparaproveedor = null;

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

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }


    public function getId(): ?int
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
