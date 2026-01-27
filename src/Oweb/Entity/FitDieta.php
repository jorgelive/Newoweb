<?php

namespace App\Oweb\Entity;

use App\Entity\User;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad FitDieta.
 * Gestiona los planes nutricionales y cálculos antropométricos de los usuarios.
 */
#[ORM\Table(name: 'fit_dieta')]
#[ORM\Entity]
class FitDieta
{
    /**
     * Identificador autoincremental de la dieta.
     * @var int
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 20)]
    private $token;

    /**
     * @var string
     */
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private $nombre;

    /**
     * @var string
     */
    #[ORM\Column(name: 'peso', type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private $peso = '60.00';

    /**
     * @var string
     */
    #[ORM\Column(name: 'indicedegrasa', type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private $indicedegrasa = '20.00';

    /**
     * @var FitTipodieta
     */
    #[ORM\ManyToOne(targetEntity: FitTipodieta::class, inversedBy: 'dietas')]
    #[ORM\JoinColumn(name: 'tipodieta_id', referencedColumnName: 'id', nullable: false)]
    protected $tipodieta;

    /**
     * @var Collection
     */
    #[ORM\OneToMany(targetEntity: FitDietacomida::class, mappedBy: 'dieta', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['numerocomida' => 'ASC'])]
    private $dietacomidas;

    /**
     * Relación con el usuario.
     * Se ajusta el JoinColumn para soportar el identificador UUID BINARY(16).
     * @var User
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        nullable: false,
        columnDefinition: 'BINARY(16)'
    )]
    private $user;

    /**
     * @var DateTime
     */
    #[ORM\Column(name: 'fecha', type: 'date')]
    private $fecha;

    /**
     * @var DateTime $creado
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private $creado;

    /**
     * @var DateTime $modificado
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private $modificado;

    public function __construct() {
        $this->dietacomidas = new ArrayCollection();
    }

    /**
     * Implementación de clonación profunda para duplicar dietas con sus comidas.
     */
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

    /**
     * LÓGICA DE CÁLCULO ANTROPOMÉTRICO
     */

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
        if(empty($this->getPesoMagro())){ return 0; }
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
        if(empty($this->getPesoMagro())){ return 0; }
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
        if(empty($this->getPesoMagro())){ return 0; }
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
        if(empty($this->getPesoMagro())){ return 0; }
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
     * GETTERS Y SETTERS EXPLÍCITOS
     */

    public function getId()
    {
        return $this->id;
    }

    public function setToken($token)
    {
        $this->token = $token;
        return $this;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function setNombre($nombre)
    {
        $this->nombre = $nombre;
        return $this;
    }

    public function getNombre()
    {
        return $this->nombre;
    }

    public function setTipodieta(FitTipodieta $tipodieta)
    {
        $this->tipodieta = $tipodieta;
        return $this;
    }

    public function getTipodieta()
    {
        return $this->tipodieta;
    }

    public function addDietacomida(FitDietacomida $dietacomida)
    {
        $dietacomida->setDieta($this);
        $this->dietacomidas[] = $dietacomida;
        return $this;
    }

    public function removeDietacomida(FitDietacomida $dietacomida)
    {
        $this->dietacomidas->removeElement($dietacomida);
    }

    public function getDietacomidas()
    {
        return $this->dietacomidas;
    }

    public function setPeso($peso)
    {
        $this->peso = $peso;
        return $this;
    }

    public function getPeso()
    {
        return $this->peso;
    }

    public function setIndicedegrasa($indicedegrasa)
    {
        $this->indicedegrasa= $indicedegrasa;
        return $this;
    }

    public function getIndicedegrasa()
    {
        return $this->indicedegrasa;
    }

    public function setUser(User $user = null)
    {
        $this->user = $user;
        return $this;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setFecha($fecha)
    {
        $this->fecha = $fecha;
        return $this;
    }

    public function getFecha()
    {
        return $this->fecha;
    }

    public function setCreado($creado)
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado()
    {
        return $this->creado;
    }

    public function setModificado($modificado)
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado()
    {
        return $this->modificado;
    }
}