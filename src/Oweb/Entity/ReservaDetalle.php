<?php

namespace App\Oweb\Entity;

use App\Oweb\Entity\ReservaTipodetalle;
use App\Entity\User;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad ReservaDetalle.
 * Gestiona los componentes específicos y servicios adicionales de una reserva.
 */
#[ORM\Table(name: 'res_detalle')]
#[ORM\Entity]
class ReservaDetalle
{

    /**
     * Identificador autoincremental del detalle.
     * @var int
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * Relación con la reserva padre.
     * @var ReservaReserva
     */
    #[ORM\ManyToOne(targetEntity: ReservaReserva::class, inversedBy: 'detalles')]
    #[ORM\JoinColumn(name: 'reserva_id', referencedColumnName: 'id', nullable: false)]
    protected $reserva;

    /**
     * Relación con el tipo de detalle de reserva.
     * @var ReservaTipodetalle
     */
    #[ORM\ManyToOne(targetEntity: ReservaTipodetalle::class)]
    #[ORM\JoinColumn(name: 'tipodetalle_id', referencedColumnName: 'id', nullable: false)]
    protected $tipodetalle;

    /**
     * Usuario vinculado a este detalle específico.
     * Se mapea como BINARY(16) para ser compatible con el ID UUID de User.
     * @var User|null
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: true
    )]
    protected $user;

    /**
     * Notas o comentarios adicionales.
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $nota;

    /**
     * Fecha de creación del registro.
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * Fecha de la última modificación del registro.
     * @var DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    /**
     * Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Genera un resumen textual del detalle.
     * @return string|null
     */
    public function getResumen(): ?string
    {
        $contenido = [];
        if(!empty($this->getUser())){
            $contenido[] = $this->getUser()->getNombre();
        }
        if(!empty($this->getNota())){
            $contenido[] = $this->getNota();
        }
        return (sprintf('%s %s', $this->getTipodetalle()->getNombre(), implode(' ', $contenido)));
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

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

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setCreado(DateTimeInterface $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    public function setModificado(DateTimeInterface $modificado): self
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

}