<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * ServicioComponente
 *
 * @ORM\Table(name="ser_componente")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioComponenteTranslation")
 */
class ServicioComponente
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
     * @var ArrayCollection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioComponenteTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=100)
     */
    private $nombre;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioComponenteitem", mappedBy="componente", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"titulo" = "ASC"})
     */
    private $componenteitems;

    /**
     * @var \App\Entity\ServicioServicio
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\ServicioServicio", mappedBy="componentes")
     * @ORM\JoinTable(name="servicio_componente",
     *      joinColumns={@ORM\JoinColumn(name="componente_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="servicio_id", referencedColumnName="id")}
     * )
     */
    protected $servicios;

    /**
     * @var \App\Entity\ServicioTipocomponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioTipocomponente")
     * @ORM\JoinColumn(name="tipocomponente_id", referencedColumnName="id", nullable=false)
     */
    protected $tipocomponente;

    /**
     * @var string
     *
     * @ORM\Column(name="duracion", type="decimal", precision=4, scale=1, nullable=true)
     */
    private $duracion;

    /**
     * @var \Doctrine\Common\Collections\Collection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioTarifa", mappedBy="componente", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"nombre" = "ASC"})
     */
    private $tarifas;

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
     */
    private $locale;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->tarifas = new ArrayCollection();
        $this->servicios = new ArrayCollection();
        $this->componenteitems = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);

            $newTarifas = new ArrayCollection();
            foreach($this->tarifas as $tarifa) {
                $newTarifa = clone $tarifa;
                $newTarifa->setComponente($this);
                $newTarifas->add($newTarifa);
            }
            $this->tarifas = $newTarifas;

            $newServicios = new ArrayCollection();
            foreach($this->servicios as $servicio) {
                $newServicio = $servicio;
                $newServicio->addComponente($this);
                $newServicios->add($newServicio);
            }
            $this->servicios = $newServicios;

            $newComponenteitems = new ArrayCollection();
            foreach($this->componenteitems as $componenteitem) {
                $newComponenteitem = clone $componenteitem;
                $newComponenteitem->setComponente($this);
                $newComponenteitems->add($newComponenteitem);
            }
            $this->componenteitems = $newComponenteitems;
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
     * Set nombre
     *
     * @param string $nombre
     *
     * @return ServicioComponente
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
     * @return ServicioComponente
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
     * @return ServicioComponente
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
     * Set tipocomponente
     *
     * @param \App\Entity\ServicioTipocomponente $tipocomponente
     *
     * @return ServicioComponente
     */
    public function setTipocomponente(\App\Entity\ServicioTipocomponente $tipocomponente = null)
    {
        $this->tipocomponente = $tipocomponente;
    
        return $this;
    }

    /**
     * Get tipocomponente
     *
     * @return \App\Entity\ServicioTipocomponente
     */
    public function getTipocomponente()
    {
        return $this->tipocomponente;
    }

    /**
     * Add tarifa
     *
     * @param \App\Entity\ServicioTarifa $tarifa
     *
     * @return ServicioComponente
     */
    public function addTarifa(\App\Entity\ServicioTarifa $tarifa)
    {
        $tarifa->setComponente($this);

        $this->tarifas[] = $tarifa;
    
        return $this;
    }

    /**
     * Remove tarifa
     *
     * @param \App\Entity\ServicioTarifa $tarifa
     */
    public function removeTarifa(\App\Entity\ServicioTarifa $tarifa)
    {
        $this->tarifas->removeElement($tarifa);
    }

    /**
     * Get tarifas
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getTarifas()
    {
        return $this->tarifas;
    }

    /**
     * Add servicio
     *
     * @param \App\Entity\ServicioServicio $servicio
     *
     * @return ServicioComponente
     */
    public function addServicio(\App\Entity\ServicioServicio $servicio)
    {
        $servicio->addComponente($this);

        $this->servicios[] = $servicio;
    
        return $this;
    }

    /**
     * Remove servicio
     *
     * @param \App\Entity\ServicioServicio $servicio
     */
    public function removeServicio(\App\Entity\ServicioServicio $servicio)
    {
        $this->servicios->removeElement($servicio);
        $servicio->removeComponente($this);
    }

    /**
     * Get servicios
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getServicios()
    {
        return $this->servicios;
    }

    /**
     * Set duracion.
     *
     * @param string|null $duracion
     *
     * @return ServicioComponente
     */
    public function setDuracion($duracion = null)
    {
        $this->duracion = $duracion;
    
        return $this;
    }

    /**
     * Get duracion.
     *
     * @return string|null
     */
    public function getDuracion()
    {
        return $this->duracion;
    }

    /**
     * Add componenteitem
     *
     * @param \App\Entity\ServicioComponenteitem $tarifa
     *
     * @return ServicioComponente
     */
    public function addComponenteitem(\App\Entity\ServicioComponenteitem $componenteitem)
    {
        $componenteitem->setComponente($this);

        $this->componenteitems[] = $componenteitem;

        return $this;
    }

    /**
     * Remove componenteitem
     *
     * @param \App\Entity\ServicioComponenteitem $componenteitem
     */
    public function removeComponenteitem(\App\Entity\ServicioComponenteitem $componenteitem)
    {
        $this->componenteitems->removeElement($componenteitem);
    }

    /**
     * Get componenteitems
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getComponenteitems()
    {
        return $this->componenteitems;
    }
}
