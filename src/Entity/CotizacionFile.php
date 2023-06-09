<?php

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * CotizacionFile
 *
 * @ORM\Table(name="cot_file")
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 */
class CotizacionFile
{

    /**
     * Para el calendario
     */
    private ?string $color;

    /**
     * @ORM\Column(type="string", length=20)
     */
    private ?string $token;

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @var string
     *
     * @ORM\Column(name="nombre", type="string", length=255)
     */
    private $nombre;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroPais")
     * @ORM\JoinColumn(name="pais_id", referencedColumnName="id", nullable=false)
     */
    private ?MaestroPais $pais;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\MaestroIdioma")
     * @ORM\JoinColumn(name="idioma_id", referencedColumnName="id", nullable=false)
     */
    private ?MaestroIdioma $idioma;

    /**
     * @ORM\Column(type="string", length=30, nullable=true)
     */
    private ?string $telefono;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionCotizacion", mappedBy="file", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"id" = "DESC"})
     */
    private Collection $cotizaciones;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionFiledocumento", mappedBy="file", cascade={"persist","remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"prioridad" = "ASC"})
     */
    private Collection $filedocumentos;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\CotizacionFilepasajero", mappedBy="file", cascade={"persist","remove"}, orphanRemoval=true)
     */
    private Collection $filepasajeros;

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

    public function __construct()
    {
        $this->cotizaciones = new ArrayCollection();
        $this->filepasajeros = new ArrayCollection();
        $this->filedocumentos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf("Id: %s.", $this->getId()) ?? '';
    }

    /**
     * @ORM\PostLoad
     */
    public function init()
    {
        $this->color = sprintf("#%02x%02x%02x", mt_rand(0x22, 0xaa), mt_rand(0x22, 0xaa), mt_rand(0x22, 0xaa));
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColor(): ?string
    {
        return $this->color;
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

    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
    
        return $this;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function setPais(MaestroPais $pais): self
    {
        $this->pais = $pais;
    
        return $this;
    }

    public function getPais(): ?MaestroPais
    {
        return $this->pais;
    }

    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getTelefono(): ?string
    {
        return $this->telefono;
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

    public function addCotizacion(?CotizacionCotizacion $cotizacion): self
    {
        $cotizacion->setFile($this);

        $this->cotizaciones[] = $cotizacion;
    
        return $this;
    }


    /**
     * Add cotizacione por inflector ingles
     */
    public function addCotizacione(CotizacionCotizacion $cotizacion): self
    {
        return $this->addCotizacion($cotizacion);
    }


    public function removeCotizacion(CotizacionCotizacion $cotizacion): bool
    {
        return $this->cotizaciones->removeElement($cotizacion);
    }

    /**
     * Remove cotizacione por inflector ingles
     */
    public function removeCotizacione(CotizacionCotizacion $cotizacion): bool
    {
        return $this->removeCotizacion($cotizacion);
    }


    public function getCotizaciones(): ?Collection
    {
        return $this->cotizaciones;
    }

    public function setIdioma(?MaestroIdioma $idioma): self
    {
        $this->idioma = $idioma;
    
        return $this;
    }

    public function getIdioma(): ?MaestroIdioma
    {
        return $this->idioma;
    }

    public function addFilepasajero(CotizacionFilepasajero $filepasajero): self
    {
        $filepasajero->setFile($this);

        $this->filepasajeros[] = $filepasajero;
    
        return $this;
    }

    public function removeFilepasajero(CotizacionFilepasajero $filepasajero): bool
    {
        return $this->filepasajeros->removeElement($filepasajero);
    }

    public function getFilepasajeros(): Collection
    {
        return $this->filepasajeros;
    }

    public function addFiledocumento(CotizacionFiledocumento $filedocumento): self
    {
        $filedocumento->setFile($this);

        $this->filedocumentos[] = $filedocumento;
    
        return $this;
    }

    public function removeFiledocumento(CotizacionFiledocumento $filedocumento): bool
    {
        return $this->filedocumentos->removeElement($filedocumento);
    }

    public function getFiledocumentos(): ?Collection
    {
        return $this->filedocumentos;
    }

}
