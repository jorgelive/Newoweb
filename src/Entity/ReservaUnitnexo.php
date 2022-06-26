<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaUnitnexo
 *
 * @ORM\Table(name="res_unitnexo")
 * @ORM\Entity
 */
class ReservaUnitnexo
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
     * @ORM\Column(type="string", length=255)
     */
    private $enlace;

    /**
     * @var \App\Entity\ReservaChanel
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaChanel", inversedBy="unitnexos")
     * @ORM\JoinColumn(name="chanel_id", referencedColumnName="id", nullable=false)
     */
    protected $chanel;

    /**
     * @var \App\Entity\ReservaUnit
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaUnit", inversedBy="unitnexos")
     * @ORM\JoinColumn(name="unit_id", referencedColumnName="id", nullable=false)
     */
    protected $unit;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $deshabilitado;

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
        return $this->getChanel()->getNombre() . ' : ' . $this->getEnlace();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEnlace(): ?string
    {
        return $this->enlace;
    }

    public function setEnlace(string $enlace): self
    {
        $this->enlace = $enlace;

        return $this;
    }

    public function isDeshabilitado(): ?bool
    {
        return $this->deshabilitado;
    }

    public function setDeshabilitado(bool $deshabilitado): self
    {
        $this->deshabilitado = $deshabilitado;

        return $this;
    }

    public function getCreado(): ?\DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(\DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?\DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(\DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    public function getChanel(): ?ReservaChanel
    {
        return $this->chanel;
    }

    public function setChanel(?ReservaChanel $chanel): self
    {
        $this->chanel = $chanel;

        return $this;
    }

    public function getUnit(): ?ReservaUnit
    {
        return $this->unit;
    }

    public function setUnit(?ReservaUnit $unit): self
    {
        $this->unit = $unit;

        return $this;
    }


}