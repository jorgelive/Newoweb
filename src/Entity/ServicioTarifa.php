<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use phpDocumentor\Reflection\Types\String_;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Translatable\Translatable;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * ServicioTarifa
 *
 * @ORM\Table(name="ser_tarifa")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\ServicioTarifaTranslation")
 */
class ServicioTarifa
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
     * @var ArrayCollection
     *
     * @ORM\OneToMany(targetEntity="App\Entity\ServicioTarifaTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected $translations;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $prorrateado;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=100)
     */
    private $nombre;

    /**
     * para mostrar al proveedor
     * @var string
     *
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $nombremostrar;

    /**
     * @var string
     *
     * @Gedmo\Translatable
     * @ORM\Column(type="string", length=100, nullable=true)
     */
    private $titulo;

    /**
     * @var MaestroMoneda
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroMoneda")
     */
    protected $moneda;

    /**
     * @var string
     *
     * @ORM\Column(type="decimal", precision=7, scale=2, nullable=true)
     */
    private $monto;

    /**
     * @var \DateTime
     *
     * @ORM\Column(type="date")
     */
    private $validezinicio;

    /**
     * @var \DateTime
     *
     * @ORM\Column(type="date")
     */
    private $validezfin;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $capacidadmin;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $capacidadmax;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $edadmin;

    /**
     * @var int
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    private $edadmax;

    /**
     * @var ServicioTipotarifa
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioTipotarifa")
     * @ORM\JoinColumn(name="tipotarifa_id", referencedColumnName="id", nullable=false)
     */
    protected $tipotarifa;

    /**
     * @var ServicioModalidadtarifa
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioModalidadtarifa")
     * @ORM\JoinColumn(name="modalidadtarifa_id", referencedColumnName="id", nullable=true)
     */
    protected $modalidadtarifa;

    /**
     * @var ServicioComponente
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioComponente", inversedBy="tarifas")
     * @ORM\JoinColumn(name="componente_id", referencedColumnName="id", nullable=false)
     */
    protected $componente;

    /**
     * @var MaestroCategoriatour
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroCategoriatour")
     * @ORM\JoinColumn(name="categoriatour_id", referencedColumnName="id", nullable=true)
     */
    protected $categoriatour;

    /**
     * @var MaestroTipopax
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroTipopax")
     */
    protected $tipopax;

    /**
     * @var \App\Entity\ServicioProvider
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\ServicioProvider", inversedBy="tarifas")
     * @ORM\JoinColumn(name="provider_id", referencedColumnName="id", nullable=true)
     */
    protected $provider;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $providernomostrable;

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

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setCreado(null);
            $this->setModificado(null);
        }

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

    public function addTranslation(ServicioTarifaTranslation $translation)
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
        $vars = [];
        $varchain = '';
        if(!empty($this->edadmin)){
           $vars[] = '>=' . $this->edadmin;
        }
        if(!empty($this->edadmax)){
            $vars[] = '<=' . $this->edadmax;
        }
        if(!empty($this->getTipopax())
            && !empty($this->getTipopax()->getId())
        ){
            $vars[] = '(' . strtoupper(substr($this->getTipopax()->getNombre(), 0,2) . ')');
        }
        if(count($vars) > 0){
            $varchain = ' | ' . implode(' ', $vars);
        }
        return sprintf('%s%s', $this->getNombre(), $varchain) ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setProrrateado(?bool $prorrateado): self
    {
        $this->prorrateado = $prorrateado;
    
        return $this;
    }

    public function isProrrateado(): ?bool
    {
        return $this->prorrateado;
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

    public function setNombremostrar(?string $nombremostrar): self
    {
        $this->nombremostrar = $nombremostrar;

        return $this;
    }

    public function getNombremostrar(): ?string
    {
        return $this->nombremostrar;
    }

    public function setMonto(?string $monto): self
    {
        $this->monto = $monto;
    
        return $this;
    }

    public function getMonto(): ?string
    {
        return $this->monto;
    }

    public function setValidezinicio(?\DateTime $validezinicio): self
    {
        $this->validezinicio = $validezinicio;
    
        return $this;
    }

    public function getValidezinicio(): ?\DateTime
    {
        return $this->validezinicio;
    }

    public function setValidezfin(?\DateTime $validezfin): self
    {
        $this->validezfin = $validezfin;
    
        return $this;
    }

    public function getValidezfin(): ?\DateTime
    {
        return $this->validezfin;
    }

    public function setCapacidadmin(?string $capacidadmin): self
    {
        $this->capacidadmin = $capacidadmin;
    
        return $this;
    }

    public function getCapacidadmin(): ?int
    {
        return $this->capacidadmin;
    }

    public function setCapacidadmax(?int $capacidadmax): self
    {
        $this->capacidadmax = $capacidadmax;
    
        return $this;
    }

    public function getCapacidadmax(): ?int
    {
        return $this->capacidadmax;
    }

    public function setEdadmin(?int $edadmin): self
    {
        $this->edadmin = $edadmin;
    
        return $this;
    }

    public function getEdadmin(): ?int
    {
        return $this->edadmin;
    }

    public function setEdadmax(?int $edadmax): self
    {
        $this->edadmax = $edadmax;
    
        return $this;
    }

    public function getEdadmax(): ?int
    {
        return $this->edadmax;
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

    public function getModificado(): \DateTime
    {
        return $this->modificado;
    }

    public function setComponente(?ServicioComponente $componente = null): self
    {
        $this->componente = $componente;
    
        return $this;
    }

    public function getComponente(): ?ServicioComponente
    {
        return $this->componente;
    }

    public function setCategoriatour(?MaestroCategoriatour $categoriatour = null): self
    {
        $this->categoriatour = $categoriatour;
    
        return $this;
    }

    public function getCategoriatour(): ?MaestroCategoriatour
    {
        return $this->categoriatour;
    }

    public function setTipopax(?MaestroTipopax $tipopax = null): self
    {
        $this->tipopax = $tipopax;
    
        return $this;
    }

    public function getTipopax(): ?MaestroTipopax
    {
        return $this->tipopax;
    }

    public function setMoneda(?MaestroMoneda $moneda = null): self
    {
        $this->moneda = $moneda;
    
        return $this;
    }

    public function getMoneda(): ?MaestroMoneda
    {
        return $this->moneda;
    }

    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;
    
        return $this;
    }

    public function getTitulo(): ?string
    {
        return $this->titulo;
    }

    public function setTipotarifa(?ServicioTipotarifa $tipotarifa = null): self
    {
        $this->tipotarifa = $tipotarifa;
    
        return $this;
    }


    public function getTipotarifa(): ?ServicioTipotarifa
    {
        return $this->tipotarifa;
    }

    public function setModalidadtarifa(?ServicioModalidadtarifa $modalidadtarifa = null): self
    {
        $this->modalidadtarifa = $modalidadtarifa;

        return $this;
    }


    public function getModalidadtarifa(): ?ServicioModalidadtarifa
    {
        return $this->modalidadtarifa;
    }


    public function setProvider(?ServicioProvider $provider = null): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getProvider(): ?ServicioProvider
    {
        return $this->provider;
    }

    public function setProvidernomostrable(?bool $providernomostrable): self
    {
        $this->providernomostrable = $providernomostrable;

        return $this;
    }

    public function isProvidernomostrable(): ?bool
    {
        return $this->providernomostrable;
    }

}
