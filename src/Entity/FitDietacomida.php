<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * FitDietacomida
 *
 * @ORM\Table(name="fit_dietacomida")
 * @ORM\Entity
 */
class FitDietacomida
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
     * @var \App\Entity\FitDieta
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\FitDieta", inversedBy="dietacomidas")
     * @ORM\JoinColumn(name="dieta_id", referencedColumnName="id", nullable=false)
     */
    protected $dieta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\FitDietaalimento", mappedBy="dietacomida", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $dietaalimentos;

    /**
     * @var int
     *
     * @ORM\Column(name="numerocomida", type="integer", options={"default": 1})
     */
    private $numerocomida;

    /**
     * @var string
     *
     * @ORM\Column(name="nota", type="string", length=255, nullable=true)
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
        $this->dietaalimentos = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
            $newDietaalimentos = new ArrayCollection();
            foreach($this->dietaalimentos as $dietaalimento) {
                $newDietaalimento = clone $dietaalimento;
                $newDietaalimento->setDietacomida($this);
                $newDietaalimentos->add($newDietaalimento);
            }
            $this->dietaalimentos = $newDietaalimentos;
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getDieta())){
            return sprintf('id: %s', $this->getId());
        }

        return sprintf("%s : %s.", $this->getDieta()->getNombre(), $this->getId()) ?? sprintf("Id: %s.", $this->getId()) ?? '';

    }

    public function getGrasaTotal()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getGrasaTotal();
        endforeach;

        return round($result, 2);
    }

    public function getCarbohidratoTotal()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getCarbohidratoTotal();
        endforeach;

        return round($result, 2);
    }

    public function getProteinaTotal()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getProteinaTotal();
        endforeach;

        return round($result, 2);
    }

    public function getProteinaTotalAlto()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getProteinaTotalAlto();
        endforeach;

        return round($result, 2);
    }


    public function getGrasaCalorias()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getGrasaCalorias();
        endforeach;

        return $result;
    }

    public function getCarbohidratoCalorias()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getCarbohidratoCalorias();
        endforeach;

        return $result;
    }

    public function getProteinaCalorias()
    {
        $result = 0;
        foreach($this->dietaalimentos as $dietaalimento):
            $result += $dietaalimento->getProteinaCalorias();
        endforeach;

        return $result;
    }

    public function getTotalCalorias()
    {
        $result = $this->getGrasaCalorias() + $this->getCarbohidratoCalorias() + $this->getProteinaCalorias();

        return $result;
    }

    public function getEnergiaCalorias()
    {
        $result = $this->getGrasaCalorias() + $this->getCarbohidratoCalorias();

        return $result;
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
     * Set dieta
     *
     * @param \App\Entity\FitDieta $dieta
     *
     * @return FitDietacomida
     */
    public function setDieta(\App\Entity\FitDieta $dieta= null)
    {
        $this->dieta = $dieta;
    
        return $this;
    }

    /**
     * Get dieta
     *
     * @return \App\Entity\FitDieta
     */
    public function getDieta()
    {
        return $this->dieta;
    }


    /**
     * Add dietaalimento
     *
     * @param \App\Entity\FitDietaalimento $dietaalimento
     *
     * @return FitDietacomida
     */
    public function addDietaalimento(\App\Entity\FitDietaalimento $dietaalimento)
    {
        $dietaalimento->setDietacomida($this);

        $this->dietaalimentos[] = $dietaalimento;
    
        return $this;
    }

    /**
     * Remove dietaalimento
     *
     * @param \App\Entity\FitDietaalimento $dietaalimento
     */
    public function removeDietaalimento(\App\Entity\FitDietaalimento $dietaalimento)
    {
        $this->dietaalimentos->removeElement($dietaalimento);
    }

    /**
     * Get dietaalimentos
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDietaalimentos()
    {
        return $this->dietaalimentos;
    }

    /**
     * Set numerocomida
     *
     * @param integer $numerocomida
     *
     * @return FitDietacomida
     */
    public function setNumerocomida($numerocomida)
    {
        $this->numerocomida = $numerocomida;
    
        return $this;
    }

    /**
     * Get numerocomida
     *
     * @return integer
     */
    public function getNumerocomida()
    {
        return $this->numerocomida;
    }

    /**
     * Set nota
     *
     * @param string $nota
     *
     * @return FitDietacomida
     */
    public function setNota($nota)
    {
        $this->nota = $nota;

        return $this;
    }

    /**
     * Get nota
     *
     * @return integer
     */
    public function getNota()
    {
        return $this->nota;
    }

    /**
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return FitDietacomida
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
     * @return FitDietacomida
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
