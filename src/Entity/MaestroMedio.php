<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;

use App\Traits\MainArchivoTrait;


/**
 * MaestroMedio
 *
 * @ORM\Table(name="mae_medio")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 * @Gedmo\TranslationEntity(class="App\Entity\MaestroMedioTranslation")
 */
class MaestroMedio
{


    use MainArchivoTrait;

    private $path = '/carga/maestromedio';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var ArrayCollection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\MaestroMedioTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=255)
     */
    private $titulo;

    /**
     * @var \App\Entity\MaestroClasemedio
     *
     * @ORM\ManyToOne(targetEntity="MaestroClasemedio", inversedBy="medios")
     * @ORM\JoinColumn(name="clasemedio_id", referencedColumnName="id", nullable=false)
     */
    protected $clasemedio;

    /**
     * @var \DateTime $creado
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $creado;

    /**
     * @var \DateTime $modificado
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private $modificado;

    /**
     * @Gedmo\Locale
     */
    private $locale;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getTranslations()
    {
        return $this->translations;
    }

    public function addTranslation(MaestroMedioTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if(empty($this->getClasemedio()) || empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId());
        }
        return sprintf('%s: %s', $this->getClasemedio()->getNombre(), $this->getNombre());
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
     * Set clasemedio
     *
     * @param \App\Entity\MaestroClasemedio $clasemedio
     *
     * @return MaestroMedio
     */
    public function setClasemedio(\App\Entity\MaestroClasemedio $clasemedio = null)
    {
        $this->clasemedio = $clasemedio;

        return $this;
    }

    /**
     * Get clasemedio
     *
     * @return \App\Entity\MaestroClasemedio
     */
    public function getClasemedio()
    {
        return $this->clasemedio;
    }


    /**
     * Set creado
     *
     * @param \DateTime $creado
     * @return MaestroMedio
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
     * @return MaestroMedio
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
     * @return MaestroMedio
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

}
