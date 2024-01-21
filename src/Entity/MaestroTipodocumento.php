<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * MaestroTipodocumento
 *
 * @ORM\Table(name="mae_tipodocumento")
 * @ORM\Entity
 */
class MaestroTipodocumento
{
    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=100)
     */
    private ?string $nombre = null;

    /**
     * @ORM\Column(type="string", length=10)
     */
    private ?string $codigo = null;

    /**
     * Joinnus
     * @ORM\Column(type="string", length=5)
     */
    private ?string $codigoju = null;

    /**
     * DDC Cusco
     * @ORM\Column(type="integer", length=2)
     */
    private ?int $codigoddc = null ;

    /**
     * Perurail
     * @ORM\Column(type="string", length=5)
     */
    private ?string $codigopr = null;

    /**
     * Consettur
     * @ORM\Column(type="integer", length=2)
     */
    private ?int $codigocon = null;

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

    public function setNombre($nombre): self
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setCodigo($codigo): self
    {
        $this->codigo = $codigo;
    
        return $this;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigoju($codigoju): self
    {
        $this->codigoju = $codigoju;

        return $this;
    }

    public function getCodigoju(): ?string
    {
        return $this->codigoju;
    }

    public function setCodigoddc($codigoddc): self
    {
        $this->codigoddc = $codigoddc;

        return $this;
    }

    public function getCodigoddc(): ?int
    {
        return $this->codigoddc;
    }

    public function setCodigopr($codigopr): self
    {
        $this->codigopr = $codigopr;

        return $this;
    }

    public function getCodigopr(): ?string
    {
        return $this->codigopr;
    }

    public function setCodigocon($codigocon): self
    {
        $this->codigocon = $codigocon;

        return $this;
    }

    public function getCodigocon(): ?string
    {
        return $this->codigocon;
    }

    public function setCreado($creado): self
    {
        $this->creado = $creado;
    
        return $this;
    }

    public function getCreado(): ?\DateTime
    {
        return $this->creado;
    }

    public function setModificado($modificado): self
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    public function getModificado(): ?\DateTime
    {
        return $this->modificado;
    }
}
