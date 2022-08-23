<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * ServicioServicio
 *
 * @ORM\Table(name="ser_servicio")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioServicioTranslation")
 */
class ServicioServicio
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
     * @ORM\Column(name="codigo", type="string", length=20)
     */
    private $codigo;

    /**
     * @var bool
     *
     * @ORM\Column(name="paralelo", type="boolean", options={"default": 0})
     */
    private $paralelo;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=100)
     */
    private $nombre;

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
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\ServicioComponente", inversedBy="servicios")
     * @ORM\JoinTable(name="servicio_componente",
     *      joinColumns={@ORM\JoinColumn(name="servicio_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="componente_id", referencedColumnName="id")}
     * )
     */
    private $componentes;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioItinerario", mappedBy="servicio", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"nombre" = "ASC"})
     */
    private $itinerarios;

    /**
     * @Gedmo\Locale
     * Used locale to override Translation listener`s locale
     * this is not a mapped field of entity metadata, just a simple property
     */
    private $locale;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->componentes = new ArrayCollection();
        $this->itinerarios = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newComponentes = new ArrayCollection();
            foreach($this->componentes as $componente) {
                $newComponente = $componente;
                $newComponente->addServicio($this);
                $newComponentes->add($newComponente);
            }
            $this->componentes = $newComponentes;
        }
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
     * Set codigo
     *
     * @param string $codigo
     *
     * @return ServicioServicio
     */
    public function setCodigo($codigo)
    {
        $this->codigo = $codigo;
    
        return $this;
    }

    /**
     * Get codigo
     *
     * @return string
     */
    public function getCodigo()
    {
        return $this->codigo;
    }

    /**
     * Set nombre
     *
     * @param string $nombre
     *
     * @return ServicioServicio
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
     * @return ServicioServicio
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
     * @return ServicioServicio
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
     * Add componente
     *
     * @param \App\Entity\ServicioComponente $componente
     *
     * @return ServicioServicio
     */
    public function addComponente(\App\Entity\ServicioComponente $componente)
    {
        //notajg: no setear el componente ni uilizar by_reference = false en el admin en el owner(en que tiene inversed)

        $this->componentes[] = $componente;
    
        return $this;
    }

    /**
     * Remove componente
     *
     * @param \App\Entity\ServicioComponente $componente
     */
    public function removeComponente(\App\Entity\ServicioComponente $componente)
    {
        $this->componentes->removeElement($componente);
    }

    /**
     * Get componentes
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getComponentes()
    {
        return $this->componentes;
    }

    /**
     * Add itinerario
     *
     * @param \App\Entity\ServicioItinerario $itinerario
     *
     * @return ServicioServicio
     */
    public function addItinerario(\App\Entity\ServicioItinerario $itinerario)
    {
        $itinerario->setServicio($this);

        $this->itinerarios[] = $itinerario;
    
        return $this;
    }

    /**
     * Remove itinerario
     *
     * @param \App\Entity\ServicioItinerario $itinerario
     */
    public function removeItinerario(\App\Entity\ServicioItinerario $itinerario)
    {
        $this->itinerarios->removeElement($itinerario);
    }

    /**
     * Get itinerarios
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getItinerarios()
    {
        return $this->itinerarios;
    }

    /**
     * Set paralelo.
     *
     * @param bool $paralelo
     *
     * @return ServicioServicio
     */
    public function setParalelo($paralelo)
    {
        $this->paralelo = $paralelo;
    
        return $this;
    }

    /**
     * Is paralelo.
     *
     * @return bool
     */
    public function isParalelo(): ?bool
    {
        return $this->paralelo;
    }

}
