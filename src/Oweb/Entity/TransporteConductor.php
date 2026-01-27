<?php
namespace App\Oweb\Entity;

use App\Entity\User;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad TransporteConductor.
 * Gestiona el perfil de conductor vinculado a un usuario del sistema.
 */
#[ORM\Table(name: 'tra_conductor')]
#[ORM\Entity]
class TransporteConductor
{
    /**
     * Identificador autoincremental del conductor.
     * @var int
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 15)]
    private $licencia;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 5)]
    private $abreviatura;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 10)]
    private $color;

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
     * Listado de servicios realizados por este conductor.
     * @var Collection<int, TransporteServicio>
     */
    #[ORM\OneToMany(mappedBy: 'conductor', targetEntity: TransporteServicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private $servicios;

    /**
     * Relación uno a uno con la entidad User.
     * Se especifica BINARY(16) para el mapeo con el ID UUID.
     * @var User
     */
    #[ORM\OneToOne(inversedBy: 'conductor', targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16)'
    )]
    private $user;

    /**
     * Constructor de la entidad.
     */
    public function __construct()
    {
        $this->servicios = new ArrayCollection();
    }

    /**
     * Representación textual de la entidad.
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre();
    }

    /**
     * Obtiene el nombre completo del conductor a través de la relación User.
     * @return string
     */
    public function getNombre()
    {
        if(is_null($this->getUser()) || is_null($this->getUser()->getFullname())) {
            return sprintf("Id: %s.", $this->getId());
        }

        return sprintf("%s", $this->getUser()->getFullname());
    }

    /*
     * -------------------------------------------------------------------------
     * GETTERS Y SETTERS EXPLÍCITOS
     * -------------------------------------------------------------------------
     */

    /**
     * Get id
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set licencia
     * @param string $licencia
     * @return TransporteConductor
     */
    public function setLicencia($licencia)
    {
        $this->licencia = $licencia;
        return $this;
    }

    /**
     * Get licencia
     * @return string
     */
    public function getLicencia()
    {
        return $this->licencia;
    }

    /**
     * Set creado
     * @param DateTime $creado
     * @return TransporteConductor
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;
        return $this;
    }

    /**
     * Get creado
     * @return DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     * @param DateTime $modificado
     * @return TransporteConductor
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;
        return $this;
    }

    /**
     * Get modificado
     * @return DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }

    /**
     * Add servicio
     * @param TransporteServicio $servicio
     * @return TransporteConductor
     */
    public function addServicio(TransporteServicio $servicio)
    {
        if (!$this->servicios->contains($servicio)) {
            $this->servicios[] = $servicio;
            $servicio->setConductor($this);
        }
        return $this;
    }

    /**
     * Remove servicio
     * @param TransporteServicio $servicio
     */
    public function removeServicio(TransporteServicio $servicio)
    {
        if ($this->servicios->removeElement($servicio)) {
            if ($servicio->getConductor() === $this) {
                $servicio->setConductor(null);
            }
        }
    }

    /**
     * Get servicios
     * @return Collection
     */
    public function getServicios()
    {
        return $this->servicios;
    }

    /**
     * Set abreviatura
     * @param string $abreviatura
     * @return TransporteConductor
     */
    public function setAbreviatura($abreviatura)
    {
        $this->abreviatura = $abreviatura;
        return $this;
    }

    /**
     * Get abreviatura
     * @return string
     */
    public function getAbreviatura()
    {
        return $this->abreviatura;
    }

    /**
     * Set user
     * @param User|null $user
     * @return TransporteConductor
     */
    public function setUser(User $user = null)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get user
     * @return User|null
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set color
     * @param string $color
     * @return TransporteConductor
     */
    public function setColor($color)
    {
        $this->color = $color;
        return $this;
    }

    /**
     * Get color
     * @return string
     */
    public function getColor()
    {
        return $this->color;
    }
}