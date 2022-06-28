<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ReservaDetalle
 *
 * @ORM\Table(name="res_detalle")
 * @ORM\Entity
 */
class ReservaDetalle
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
     * @var \App\Entity\ReservaReserva
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaReserva", inversedBy="detalles")
     * @ORM\JoinColumn(name="reserva_id", referencedColumnName="id", nullable=false)
     */
    protected $reserva;

    /**
     * @var \App\Entity\ReservaTipodetallle
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ReservaTipodetalle")
     * @ORM\JoinColumn(name="tipodetalle_id", referencedColumnName="id", nullable=false)
     */
    protected $tipodetalle;

    /**
     * @var \App\Entity\UserUser
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\UserUser")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=true)
     */
    protected $user;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $nota;

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
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNota(): ?string
    {
        return $this->nota;
    }

    public function setNota(?string $nota): self
    {
        $this->nota = $nota;

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

    public function getReserva(): ?ReservaReserva
    {
        return $this->reserva;
    }

    public function setReserva(?ReservaReserva $reserva): self
    {
        $this->reserva = $reserva;

        return $this;
    }

    public function getTipodetalle(): ?ReservaTipodetalle
    {
        return $this->tipodetalle;
    }

    public function setTipodetalle(?ReservaTipodetalle $tipodetalle): self
    {
        $this->tipodetalle = $tipodetalle;

        return $this;
    }

    public function getUser(): ?UserUser
    {
        return $this->user;
    }

    public function setUser(?UserUser $user): self
    {
        $this->user = $user;

        return $this;
    }

}