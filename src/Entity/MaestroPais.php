<?php

namespace App\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * MaestroPais
 */
#[ORM\Table(name: 'mae_pais')]
#[ORM\Entity]
class MaestroPais
{

    public const DB_VALOR_PERU = 117;

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    private ?string $nombre;

    /**
     * MC
     */
    #[ORM\Column(type: 'integer', length: 3)]
    private ?int $codigomc = null;

    /**
     * Perurail
     */
    #[ORM\Column(type: 'integer', length: 3)]
    private ?int $codigopr = null;

    /**
     * Consettur
     * @var integer
     */
    #[ORM\Column(type: 'integer', length: 40)]
    private ?int $codigocon = null;



    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $creado;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $modificado;

    /**
     * @return string
     */
    public function __toString()
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

    public function getProcedenciaMcNombre(): string
    {
        //20 Bolivia
        //41 Ecuador
        //32 Colombia

        if($this->id == 117){
            return 'Peruano';
        }elseif(in_array($this->id, [20, 41, 32])){
            return 'Países CAN y Residente extranjero';
        }
        return 'Extranjero';
    }

    public function getProcedenciaMcCodigo(): int
    {
        if($this->id == 117){
            return 2;
        }elseif(in_array($this->id, [])){
            return 3;
        }
        return 1;
    }

    public function setCodigomc(int $codigomc): self
    {
        $this->codigomc = $codigomc;

        return $this;
    }

    public function getCodigomc(): ?int
    {
        return $this->codigomc;
    }

    public function setCodigopr(string $codigopr): self
    {
        $this->codigopr = $codigopr;

        return $this;
    }

    public function getCodigopr(): ?string
    {
        return $this->codigopr;
    }

    public function setCodigocon(string $codigocon): self
    {
        $this->codigocon = $codigocon;

        return $this;
    }

    public function getCodigocon(): ?string
    {
        return $this->codigocon;
    }


    public function setCreado(?DateTime $creado): self
    {
        $this->creado = $creado;
    
        return $this;
    }

    public function getCreado(): ?DateTime
    {
        return $this->creado;
    }

    public function setModificado(?DateTime $modificado): self
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    public function getModificado(): ?DateTime
    {
        return $this->modificado;
    }
}
