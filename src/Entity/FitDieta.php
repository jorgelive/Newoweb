<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * FitDieta
 *
 * @ORM\Table(name="fit_dieta")
 * @ORM\Entity
 */
class FitDieta
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
     * @ORM\Column(type="string", length=20)
     */
    private $token;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=255)
     */
    private $nombre;

    /**
     * @var string
     *
     * @ORM\Column(name="peso", type="decimal", precision=5, scale=2, nullable=false)
     */
    private $peso = '60.00';

    /**
     * @var string
     *
     * @ORM\Column(name="indicedegrasa", type="decimal", precision=5, scale=2, nullable=false)
     */
    private $indicedegrasa = '20.00';

    /**
     * @var \App\Entity\FitTipodieta
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\FitTipodieta" , inversedBy="dietas")
     * @ORM\JoinColumn(name="tipodieta_id", referencedColumnName="id", nullable=false)
     */
    protected $tipodieta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\FitDietacomida", mappedBy="dieta", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"numerocomida" = "ASC"})
     */
    private $dietacomidas;

    /**
     * @var \App\Entity\UserUser
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\UserUser" )
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=false)
     */
    private $user;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="fecha", type="date")
     */
    private $fecha;

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

    public function __construct() {
        $this->dietacomidas = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $this->setToken(mt_rand());
            $newDietacomidas = new ArrayCollection();
            foreach($this->dietacomidas as $dietacomida) {
                $newDietacomida = clone $dietacomida;
                $newDietacomida->setDieta($this);
                $newDietacomidas->add($newDietacomida);
            }
            $this->dietacomidas = $newDietacomidas;
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getUser())){
            return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
        }


        return sprintf("%s : %s.", $this->getUser()->getFullname(), $this->getNombre()) ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getPesoMagro()
    {
        if(empty($this->getPeso()) || empty($this->getIndicedegrasa())){
            return 0;
        }

        return round($this->getPeso() - ($this->getPeso()*$this->getIndicedegrasa()/100), 2);

    }

    public function getGrasaTotal()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getGrasaTotal();
        endforeach;

        return round($result, 2);
    }

    public function getGrasaTotalPorKilogramo()
    {
        if(empty($this->getPesoMagro())){
            return 0;
        }

        return round($this->getGrasaTotal()/ $this->getPesoMagro(), 2);
    }

    public function getCarbohidratoTotal()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getCarbohidratoTotal();
        endforeach;

        return round($result, 2);
    }

    public function getCarbohidratoTotalPorKilogramo()
    {
        if(empty($this->getPesoMagro())){
            return 0;
        }

        return round($this->getCarbohidratoTotal()/ $this->getPesoMagro(), 2);
    }

    public function getProteinaTotal()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getProteinaTotal();
        endforeach;

        return round($result, 2);
    }

    public function getProteinaTotalPorKilogramo()
    {
        if(empty($this->getPesoMagro())){
            return 0;
        }

        return round($this->getProteinaTotal()/ $this->getPesoMagro(), 2);
    }

    public function getProteinaTotalAlto()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getProteinaTotalAlto();
        endforeach;

        return round($result, 2);
    }

    public function getProteinaTotalAltoPorKilogramo()
    {
        if(empty($this->getPesoMagro())){
            return 0;
        }

        return round($this->getProteinaTotalAlto()/ $this->getPesoMagro(), 2);
    }

    public function getGrasaCalorias()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getGrasaCalorias();
        endforeach;

        return round($result, 2);
    }

    public function getCarbohidratoCalorias()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getCarbohidratoCalorias();
        endforeach;

        return round($result, 2);
    }

    public function getProteinaCalorias()
    {
        $result = 0;
        foreach($this->dietacomidas as $dietacomida):
            $result += $dietacomida->getProteinaCalorias();
        endforeach;

        return round($result, 2);
    }

    public function getTotalCalorias()
    {
        $result = $this->getGrasaCalorias() + $this->getCarbohidratoCalorias() + $this->getProteinaCalorias();

        return round($result, 2);
    }

    public function getEnergiaCalorias()
    {
        $result = $this->getGrasaCalorias() + $this->getCarbohidratoCalorias();

        return round($result, 2);
    }

    public function getGrasaPorcentaje()
    {
        if(empty($this->getTotalCalorias())){return 0;}

        $result = $this->getGrasaCalorias() / $this->getTotalCalorias() * 100;

        return round($result, 2);
    }

    public function getCarbohidratoPorcentaje()
    {
        if(empty($this->getTotalCalorias())){return 0;}

        $result = $this->getCarbohidratoCalorias() / $this->getTotalCalorias() * 100;

        return round($result, 2);
    }

    public function getProteinaPorcentaje()
    {
        if(empty($this->getTotalCalorias())){return 0;}

        $result = $this->getProteinaCalorias() / $this->getTotalCalorias() * 100;

        return round($result, 2);
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
     * Set token
     *
     * @param string $token
     *
     * @return FitDieta
     */
    public function setToken($token)
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Get token
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     *
     * @return FitDieta
     */
    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    /**
     * Get nombre
     *
     * @return string
     */
    public function getNombre()
    {
        return $this->nombre;
    }


    /**
     * Set tipodieta
     *
     * @param \App\Entity\FitTipodieta $tipodieta
     *
     * @return FitDieta
     */
    public function setTipodieta(\App\Entity\FitTipodieta $tipodieta)
    {
        $this->tipodieta = $tipodieta;
    
        return $this;
    }

    /**
     * Get tipodieta
     *
     * @return \App\Entity\FitTipodieta
     */
    public function getTipodieta()
    {
        return $this->tipodieta;
    }

    /**
     * Add dietacomida
     *
     * @param \App\Entity\FitDietacomida $dietacomida
     *
     * @return FitDieta
     */
    public function addDietacomida(\App\Entity\FitDietacomida $dietacomida)
    {
        $dietacomida->setDieta($this);

        $this->dietacomidas[] = $dietacomida;
    
        return $this;
    }

    /**
     * Remove dietacomida
     *
     * @param \App\Entity\FitDietacomida $dietacomida
     */
    public function removeDietacomida(\App\Entity\FitDietacomida $dietacomida)
    {
        $this->dietacomidas->removeElement($dietacomida);
    }

    /**
     * Get dietacomidas
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDietacomidas()
    {
        return $this->dietacomidas;
    }

    /**
     * Set peso.
     *
     * @param string $peso
     *
     * @return FitDieta
     */
    public function setPeso($peso)
    {
        $this->peso = $peso;
    
        return $this;
    }

    /**
     * Get peso.
     *
     * @return string
     */
    public function getPeso()
    {
        return $this->peso;
    }

    /**
     * Set indicedegrasa.
     *
     * @param string $indicedegrasa
     *
     * @return FitDieta
     */
    public function setIndicedegrasa($indicedegrasa)
    {
        $this->indicedegrasa= $indicedegrasa;

        return $this;
    }

    /**
     * Get indicedegrasa.
     *
     * @return string
     */
    public function getIndicedegrasa()
    {
        return $this->indicedegrasa;
    }

    /**
     * Set user
     *
     * @param \App\Entity\UserUser $user
     *
     * @return FitDieta
     */
    public function setUser(\App\Entity\UserUser $user = null)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return \App\Entity\UserUser
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set fecha
     *
     * @param \DateTime $fecha
     *
     * @return FitDieta
     */
    public function setFecha($fecha)
    {
        $this->fecha = $fecha;

        return $this;
    }

    /**
     * Get fecha
     *
     * @return \DateTime
     */
    public function getFecha()
    {
        return $this->fecha;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return FitDieta
     */
    public function setCreado($creado)
    {
        $this->creado = $creado;

        return $this;
    }

    /**
     * Get creado
     *
     * @return \DateTime
     */
    public function getCreado()
    {
        return $this->creado;
    }

    /**
     * Set modificado
     *
     * @param \DateTime $modificado
     *
     * @return FitDieta
     */
    public function setModificado($modificado)
    {
        $this->modificado = $modificado;

        return $this;
    }

    /**
     * Get modificado
     *
     * @return \DateTime
     */
    public function getModificado()
    {
        return $this->modificado;
    }


}
