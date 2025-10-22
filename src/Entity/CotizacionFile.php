<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Table(name: 'cot_file')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class CotizacionFile
{
    /**
     * Para el calendario (no mapeado a DB)
     */
    private ?string $color = null;

    // Consigna: inicializar strings a null por compatibilidad con Symfony
    #[ORM\Column(type: 'string', length: 20)]
    private ?string $token = null;

    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private ?string $nombre = null;

    #[ORM\ManyToOne(targetEntity: 'MaestroPais')]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroPais $pais = null;

    #[ORM\ManyToOne(targetEntity: 'MaestroIdioma')]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroIdioma $idioma = null;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $catalogo = false;

    #[ORM\OneToMany(targetEntity: 'CotizacionCotizacion', mappedBy: 'file', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $cotizaciones;

    #[ORM\OneToMany(targetEntity: 'CotizacionFiledocumento', mappedBy: 'file', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['prioridad' => 'ASC'])]
    private Collection $filedocumentos;

    #[ORM\OneToMany(targetEntity: 'CotizacionFilepasajero', mappedBy: 'file', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $filepasajeros;

    // Fechas tipadas a DateTimeInterface para compatibilidad con Timestampable
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    public function __construct()
    {
        $this->cotizaciones   = new ArrayCollection();
        $this->filepasajeros  = new ArrayCollection();
        $this->filedocumentos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    #[ORM\PostLoad]
    public function init(): void
    {
        $this->color = sprintf('#%02x%02x%02x', mt_rand(0x22, 0xaa), mt_rand(0x22, 0xaa), mt_rand(0x22, 0xaa));
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

    public function setIdioma(?MaestroIdioma $idioma): self
    {
        $this->idioma = $idioma;
        return $this;
    }

    public function getIdioma(): ?MaestroIdioma
    {
        return $this->idioma;
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

    public function setCatalogo(bool $catalogo): self
    {
        $this->catalogo = $catalogo;
        return $this;
    }

    public function isCatalogo(): bool
    {
        return $this->catalogo;
    }

    public function addCotizacion(?CotizacionCotizacion $cotizacion): self
    {
        if ($cotizacion) {
            $cotizacion->setFile($this);
            $this->cotizaciones[] = $cotizacion;
        }
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

    public function getCotizaciones(): Collection
    {
        return $this->cotizaciones;
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

    public function getFiledocumentos(): Collection
    {
        return $this->filedocumentos;
    }

    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }
}
