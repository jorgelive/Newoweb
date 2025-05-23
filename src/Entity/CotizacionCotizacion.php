<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Translatable\Translatable;

/**
 * CotizacionCotizacion
 *
 * @ORM\Table(name="cot_cotizacion")
 * @ORM\Entity
 * @Gedmo\TranslationEntity(class="App\Entity\CotizacionCotizacionTranslation")
 */
class CotizacionCotizacion
{

    /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToMany(targetEntity="CotizacionCotizacionTranslation", mappedBy="object", cascade={"persist", "remove"})
     */
    protected Collection $translations;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private ?string $token;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private ?string $tokenoperaciones;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private ?string $nombre;

    /**
     * @Gedmo\Translatable
     * @ORM\Column(type="text")
     */
    private ?string $resumen = null;

    /**
     * @ORM\Column(type="text", columnDefinition= "longtext AS (resumen) VIRTUAL NULL", generated="ALWAYS", insertable=false, updatable=false )
     */
    private ?string $resumenoriginal = null;

    /**
     * @ORM\Column(type="integer")
     */
    private ?int $numeropasajeros = null;

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=false)
     */
    private ?string $comision = '20.00';

    /**
     * @ORM\Column(type="decimal", precision=5, scale=2, nullable=false)
     */
    private ?string $adelanto = '50.00';

    /**
     * Nombre de hotel oculto
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $hoteloculto = false;

    /**
     * Precio Oculto en resumen
     * @var bool
     *
     * @ORM\Column(type="boolean", options={"default": 0})
     */
    private $precioocultoresumen = false;

    /**
     * @ORM\ManyToOne(targetEntity="CotizacionEstadocotizacion")
     * @ORM\JoinColumn(name="estadocotizacion_id", referencedColumnName="id", nullable=false)
     */
    protected ?CotizacionEstadocotizacion $estadocotizacion;

    /**
     * @ORM\ManyToOne(targetEntity="CotizacionFile", inversedBy="cotizaciones")
     * @ORM\JoinColumn(name="file_id", referencedColumnName="id", nullable=false)
     */
    private ?CotizacionFile $file;

    /**
     * @ORM\ManyToOne(targetEntity="CotizacionCotpolitica", inversedBy="cotizaciones")
     * @ORM\JoinColumn(name="cotpolitica_id", referencedColumnName="id", nullable=false)
     */
    private ?CotizacionCotpolitica $cotpolitica;

    /**
     * @ORM\ManyToMany(targetEntity="CotizacionCotnota", inversedBy="cotizaciones")
     * @ORM\JoinTable(name="cotizacion_cotnota",
     *      joinColumns={@ORM\JoinColumn(name="cotizacion_id", referencedColumnName="id")},
     *      inverseJoinColumns={@ORM\JoinColumn(name="cotnota_id", referencedColumnName="id")}
     * )
     */
    private Collection $cotnotas;

    /**
     * @ORM\OneToMany(targetEntity="CotizacionCotservicio", mappedBy="cotizacion", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"fechahorainicio" = "ASC"})
     */
    private Collection $cotservicios;

    /**
     * Almacen de imagenes
     */
    private Collection $portadafotos;

    /**
     * @ORM\Column(type="date")
     */
    private ?\DateTime $fecha;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $fechaingreso;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $fechasalida;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $creado;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $modificado;

    /**
     * @Gedmo\Locale
     */
    private ?string $locale = null;

    public function __construct() {
        $this->cotservicios = new ArrayCollection();
        $this->cotnotas = new ArrayCollection();
        $this->translations = new ArrayCollection();
        $this->portadafotos = new ArrayCollection();
    }

    public function __clone() {
        if($this->id) {
            $this->id = null;
            $this->setFecha(new \DateTime('today'));
            $this->setCreado(null);
            $this->setModificado(null);
            $this->setToken(mt_rand());
            $this->setTokenoperaciones(mt_rand());
            $newCotservicios = new ArrayCollection();
            foreach($this->cotservicios as $cotservicio) {
                $newCotservicio = clone $cotservicio;
                $newCotservicio->setCotizacion($this);
                $newCotservicios->add($newCotservicio);
            }
            $this->cotservicios = $newCotservicios;
        }
    }

    public function __toString(): string
    {

        if($this->getFile()->isCatalogo() === true){
            return sprintf("%s - %s", $this->getNumerocotizacion(), $this->getTitulo());

        }elseif($this->getEstadocotizacion()->getId() == CotizacionEstadocotizacion::DB_VALOR_PENDIENTE || $this->getEstadocotizacion()->getId() == CotizacionEstadocotizacion::DB_VALOR_WAITING){
            return sprintf("%s %s x%s", $this->getNumerocotizacion(), $this->getFile()->getNombre(), $this->getNumeropasajeros());
        }else{
            return sprintf("%s %s x%s (%s)", $this->getNumerocotizacion(), $this->getFile()->getNombre(), $this->getNumeropasajeros(), $this->getEstadocotizacion()->getNombre());
        }
    }

    public function getNumerocotizacion(): string
    {
        return sprintf("OPC%05d", $this->getId());
    }

    public function getTitulo(): string
    {
        return substr(str_replace("&nbsp;", '', strip_tags($this->resumen)), 0, 100) . '...';
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

    public function addTranslation(CotizacionCotizacionTranslation $translation)
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setToken(?string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setTokenoperaciones(?string $tokenoperaciones): self
    {
        $this->tokenoperaciones = $tokenoperaciones;

        return $this;
    }

    public function getTokenoperaciones(): ?string
    {
        return $this->tokenoperaciones;
    }

    public function getCodigo(): ?string
    {
        return 'OPC-'.sprintf('%05d', $this->id);
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

    public function setNumeropasajeros(?int $numeropasajeros): self
    {
        $this->numeropasajeros = $numeropasajeros;
    
        return $this;
    }

    public function getNumeropasajeros(): ?int
    {
        return $this->numeropasajeros;
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

    public function setEstadocotizacion(?CotizacionEstadocotizacion $estadocotizacion): self
    {
        $this->estadocotizacion = $estadocotizacion;
    
        return $this;
    }


    public function getEstadocotizacion(): ?CotizacionEstadocotizacion
    {
        return $this->estadocotizacion;
    }

    public function setFile(?CotizacionFile $file): self
    {
        $this->file = $file;
    
        return $this;
    }

    public function getFile(): ?CotizacionFile
    {
        return $this->file;
    }

    public function addCotservicio(CotizacionCotservicio $cotservicio): self
    {
        $cotservicio->setCotizacion($this);

        $this->cotservicios[] = $cotservicio;
    
        return $this;
    }

    public function removeCotservicio(CotizacionCotservicio $cotservicio)
    {
        $this->cotservicios->removeElement($cotservicio);
    }

    public function getCotservicios(): Collection
    {
        return $this->cotservicios;
    }


    public function setHoteloculto(bool $hoteloculto): self
    {
        $this->hoteloculto = $hoteloculto;

        return $this;
    }

    public function isHoteloculto(): ?bool
    {
        return $this->hoteloculto;
    }

    public function setPrecioocultoresumen(bool $precioocultoresumen): self
    {
        $this->precioocultoresumen = $precioocultoresumen;

        return $this;
    }

    public function isPrecioocultoresumen(): ?bool
    {
        return $this->precioocultoresumen;
    }

    public function addPortadafoto(MaestroMedio $portadafoto): self
    {
        //doctrine no ejecuta el constructor
        if(!isset($this->portadafotos)){
            $this->portadafotos = new ArrayCollection();
        }
        $this->portadafotos[] = $portadafoto;

        return $this;
    }

    public function getPortadafotos(): Collection
    {
        //doctrine no ejecuta el constructor
        if(!isset($this->portadafotos)){
            $this->portadafotos = new ArrayCollection();
        }
        return $this->portadafotos;
    }

    public function setComision(?string $comision): self
    {
        $this->comision = $comision;
    
        return $this;
    }

    public function getComision(): ?string
    {
        return $this->comision;
    }

    public function setAdelanto(?string $adelanto): self
    {
        $this->adelanto = $adelanto;

        return $this;
    }

    public function getAdelanto(): ?string
    {
        return $this->adelanto;
    }

    public function setCotpolitica(?CotizacionCotpolitica $cotpolitica): self
    {
        $this->cotpolitica = $cotpolitica;
    
        return $this;
    }

    public function getCotpolitica(): ?CotizacionCotpolitica
    {
        return $this->cotpolitica;
    }

    public function addCotnota(CotizacionCotnota $cotnota): self
    {
        //notajg: no setear el componente ni utilizar by_reference = false en el admin en el owner(en que tiene inversed)

        $this->cotnotas[] = $cotnota;
    
        return $this;
    }

    public function removeCotnota(CotizacionCotnota $cotnota)
    {
        return $this->cotnotas->removeElement($cotnota);
    }

    public function getCotnotas(): Collection
    {
        return $this->cotnotas;
    }


    public function setResumen(?string $resumen = null): self
    {
        $this->resumen = $resumen;
    
        return $this;
    }

    public function getResumen(): ?string
    {
        return $this->resumen;
    }

    public function getResumenoriginal(): ?string
    {
        return $this->resumenoriginal;
    }


    public function setFecha(\DateTime $fecha): self
    {
        $this->fecha = $fecha;

        return $this;
    }

    public function getFecha(): ?\DateTime
    {
        return $this->fecha;
    }

    public function setFechaingreso(?\DateTime $fechaingreso): self
    {
        $this->fechaingreso = $fechaingreso;

        return $this;
    }

    public function getFechaingreso(): ?\DateTime
    {
        return $this->fechaingreso;
    }


    public function setFechasalida(?\DateTime $fechasalida): self
    {
        $this->fechasalida = $fechasalida;

        return $this;
    }

    public function getFechasalida(): ?\DateTime
    {
        return $this->fechasalida;
    }

}
