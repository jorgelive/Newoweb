<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

/**
 * MaestroClasemedio
 *
 * @ORM\Table(name="mae_clasemedio")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\MaestroClasemedioTranslation")
 */
class MaestroClasemedio implements Translatable
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
    private $nombre;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=100, nullable=false)
     */
    private $titulo;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\MaestroMedio", mappedBy="clasemedio", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private $medios;

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
     * @Gedmo\Locale
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    public function __construct()
    {
        $this->medios = new ArrayCollection();
    }


    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
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
     * Set nombre
     *
     * @param string $nombre
     *
     * @return MaestroClasemedio
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
     * Set creado
     *
     * @param \DateTime $creado
     *
     * @return MaestroClasemedio
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
     * @return MaestroClasemedio
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

    /**
     * Set titulo.
     *
     * @param string $titulo
     *
     * @return MaestroClasemedio
     */
    public function setTitulo($titulo)
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    /**
     * Get titulo.
     *
     * @return string
     */
    public function getTitulo()
    {
        return $this->titulo;
    }

    /**
     * Add medio.
     *
     * @param \App\Entity\MaestroMedio $maestromedio
     *
     * @return MaestroClasemedio
     */
    public function addMedio(\App\Entity\MaestroMedio $medio)
    {
        $medio->setClasemedio($this);

        $this->medios[] = $medio;

        return $this;
    }

    /**
     * Remove medio.
     *
     * @param \App\Entity\Maestromedio $medio
     *
     * @return MaestroClasemedio
     */
    public function removeMedio(MaestroMedio $medio): self
    {
        if($this->medios->removeElement($medio)) {
            // set the owning side to null (unless already changed)
            if($medio->getClasemedio() === $this) {
                $medio->setClasemedio(null);
            }
        }

        return $this;
    }

    /**
     * Get medios.
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getMedios()
    {
        return $this->medios;
    }


}
