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
 */
class ServicioComponente
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
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $anticipacionalerta;

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
     * @ORM\Column(type="decimal", precision=4, scale=1, nullable=true)
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
     * Constructor
     */
    public function __construct()
    {
        $this->tarifas = new ArrayCollection();
        $this->servicios = new ArrayCollection();
        $this->componenteitems = new ArrayCollection();
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

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setAnticipacionalerta(?int $anticipacionalerta): self
    {
        $this->anticipacionalerta = $anticipacionalerta;

        return $this;
    }

    public function getAnticipacionalerta(): ?int
    {
        return $this->anticipacionalerta;
    }

    public function setCreado(?\DateTime $creado): self
    {
        $this->creado = $creado;
    
        return $this;
    }

    public function getCreado(): ?\DateTime
    {
        return $this->creado;
    }

    public function setModificado(?\DateTime $modificado): self
    {
        $this->modificado = $modificado;
    
        return $this;
    }

    public function getModificado(): ?\DateTime
    {
        return $this->modificado;
    }

    public function setTipocomponente(?ServicioTipocomponente $tipocomponente = null): self
    {
        $this->tipocomponente = $tipocomponente;
    
        return $this;
    }

    public function getTipocomponente(): ServicioTipocomponente
    {
        return $this->tipocomponente;
    }

    public function addTarifa(ServicioTarifa $tarifa): self
    {
        $tarifa->setComponente($this);

        $this->tarifas[] = $tarifa;
    
        return $this;
    }

    public function removeTarifa(ServicioTarifa $tarifa)
    {
        $this->tarifas->removeElement($tarifa);
    }

    public function getTarifas(): Collection
    {
        return $this->tarifas;
    }

    public function addServicio(ServicioServicio $servicio): self
    {
        $servicio->addComponente($this);

        $this->servicios[] = $servicio;
    
        return $this;
    }

    public function removeServicio(ServicioServicio $servicio)
    {
        $this->servicios->removeElement($servicio);
        $servicio->removeComponente($this);
    }

    public function getServicios(): Collection
    {
        return $this->servicios;
    }

    public function setDuracion(?string $duracion = null): self
    {
        $this->duracion = $duracion;
    
        return $this;
    }

    public function getDuracion(): ?string
    {
        return $this->duracion;
    }

    public function addComponenteitem(ServicioComponenteitem $componenteitem): self
    {
        $componenteitem->setComponente($this);

        $this->componenteitems[] = $componenteitem;

        return $this;
    }

    public function removeComponenteitem(ServicioComponenteitem $componenteitem)
    {
        $this->componenteitems->removeElement($componenteitem);
    }

    public function getComponenteitems(): Collection
    {
        return $this->componenteitems;
    }
}
