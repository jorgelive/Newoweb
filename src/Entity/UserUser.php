<?php

namespace App\Entity;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Sonata\UserBundle\Entity\BaseUser as BaseUser;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * User
 */
#[ORM\Table(name: 'fos_user_user')]
#[ORM\Entity]
class UserUser extends BaseUser
{

    /**
     * @var integer
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;


    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $firstname;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private $lastname;

    /**
     * @var UserDependencia
     */
    #[ORM\ManyToOne(targetEntity: 'UserDependencia', inversedBy: 'users')]
    protected $dependencia;

    /**
     * @var UserArea
     */
    #[ORM\ManyToOne(targetEntity: 'UserArea', inversedBy: 'users')]
    protected $area;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: 'UserCuenta', mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private $cuentas;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: 'CuentaMovimiento', mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private $movimientos;

    /**
     * @var TransporteConductor
     */
    #[ORM\OneToOne(targetEntity: 'TransporteConductor', mappedBy: 'user')]
    private $conductor;

    public function __construct() {

        //parent::__construct();

        $this->cuentas = new ArrayCollection();
        $this->movimientos = new ArrayCollection();

    }


    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }


    /**
     * Set firstname
     *
     * @param string $firsrname
     * @return UserUser
     */
    public function setFirstname($firstname = null)
    {
        $this->firstname = $firstname;

        return $this;
    }

    /**
     * Get firstname
     *
     * @return string
     */
    public function getFirstname()
    {
        return $this->firstname;
    }


    /**
     * Set lastname
     *
     * @param string $lastname
     * @return UserUser
     */
    public function setLastname($lastname = null)
    {
        $this->lastname = $lastname;

        return $this;
    }

    /**
     * Get lastname
     *
     * @return string
     */
    public function getLastname()
    {
        return $this->lastname;
    }

    /**
     * Get fullname
     *
     * @return string
     */
    public function getFullname()
    {
        return $this->firstname . ' ' . $this->lastname;
    }


    /**
     * Set dependencia
     *
     * @param UserDependencia $dependencia
     * @return UserUser
     */
    public function setDependencia(UserDependencia $dependencia = null)
    {
        $this->dependencia = $dependencia;

        return $this;
    }

    /**
     * Get dependencia
     *
     * @return UserDependencia
     */
    public function getDependencia()
    {
        return $this->dependencia;
    }

    /**
     * Set area
     *
     * @param UserArea $area
     * @return UserUser
     */
    public function setArea(UserArea $area = null)
    {
        $this->area = $area;

        return $this;
    }

    /**
     * Get area
     *
     * @return UserArea
     */
    public function getArea()
    {
        return $this->area;
    }

    /**
     * Get area
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->getFirstname().' '.$this->getLastname();
    }

    /**
     * Add cuenta
     *
     * @param UserCuenta $cuenta
     * @return UserUser
     */
    public function addCuenta(UserCuenta $cuenta)
    {
        $cuenta->setUser($this);

        $this->cuentas[] = $cuenta;

        return $this;
    }

    /**
     * Remove cuenta
     *
     * @param UserCuenta $cuenta
     */
    public function removeCuenta(UserCuenta $cuenta)
    {
        $this->cuentas->removeElement($cuenta);
    }

    /**
     * Get cuentas
     *
     * @return Collection
     */
    public function getCuentas()
    {
        return $this->cuentas;
    }

    /**
     * Set conductor
     *
     * @param TransporteConductor $conductor
     *
     * @return UserUser
     */
    public function setConductor(TransporteConductor $conductor = null)
    {
        $this->conductor = $conductor;
    
        return $this;
    }

    /**
     * Get conductor
     *
     * @return TransporteConductor
     */
    public function getConductor()
    {
        return $this->conductor;
    }

    /**
     * Add movimiento.
     *
     * @param CuentaMovimiento $movimiento
     *
     * @return UserUser
     */
    public function addMovimiento(CuentaMovimiento $movimiento)
    {
        $movimiento->setUser($this);

        $this->movimientos[] = $movimiento;
    
        return $this;
    }

    /**
     * Remove movimiento.
     *
     * @param CuentaMovimiento $movimiento
     *
     * @return boolean TRUE if this collection contained the specified element, FALSE otherwise.
     */
    public function removeMovimiento(CuentaMovimiento $movimiento)
    {
        return $this->movimientos->removeElement($movimiento);
    }

    /**
     * Get movimientos.
     *
     * @return Collection
     */
    public function getMovimientos()
    {
        return $this->movimientos;
    }
}
