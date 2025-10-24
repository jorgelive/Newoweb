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

    #[ORM\OneToMany(mappedBy: 'file', targetEntity: 'CotizacionCotizacion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $cotizaciones;

    #[ORM\OneToMany(mappedBy: 'file', targetEntity: 'CotizacionFiledocumento', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['prioridad' => 'ASC'])]
    private Collection $filedocumentos;

    #[ORM\OneToMany(mappedBy: 'file', targetEntity: 'CotizacionFilepasajero', cascade: ['persist', 'remove'], orphanRemoval: true)]
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
        // Aquí llamas al método
        $this->color = $this->getColorFromId($this->id);
    }

    public function getColorFromId(int $id): string
    {
        $h = ($id * 37) % 360; // tono pseudoaleatorio
        $s = 60; // saturación %
        $v = 90; // brillo %
        return $this->hsvToHex($h, $s, $v);
    }

    private function hsvToHex(float $h, float $s, float $v): string
    {
        $s /= 100; $v /= 100;
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;
        if ($h < 60) list($r, $g, $b) = [$c, $x, 0];
        elseif ($h < 120) list($r, $g, $b) = [$x, $c, 0];
        elseif ($h < 180) list($r, $g, $b) = [0, $c, $x];
        elseif ($h < 240) list($r, $g, $b) = [0, $x, $c];
        elseif ($h < 300) list($r, $g, $b) = [$x, 0, $c];
        else list($r, $g, $b) = [$c, 0, $x];
        return sprintf('#%02x%02x%02x', ($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255);
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
