<?php

namespace App\Oweb\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad CotizacionFile.
 *
 * Representa el expediente o contenedor principal ("File") que agrupa pasajeros,
 * documentos y cotizaciones asociadas dentro del sistema.
 */
#[ORM\Table(name: 'cot_file')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class CotizacionFile
{
    /**
     * Color hexadecimal asignado dinámicamente para representación en calendario.
     * No se persiste en base de datos; se calcula en el evento postLoad.
     */
    private ?string $color = null;

    /**
     * Token de seguridad/identificación único para el file.
     */
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $token = null;

    /**
     * Identificador único del expediente (File).
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * Nombre o título descriptivo del expediente.
     */
    #[ORM\Column(name: 'nombre', type: 'string', length: 255)]
    private ?string $nombre = null;

    /**
     * País de origen/asociado al expediente.
     */
    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroPais $pais = null;

    /**
     * Idioma principal configurado para el expediente.
     */
    #[ORM\ManyToOne(targetEntity: MaestroIdioma::class)]
    #[ORM\JoinColumn(name: 'idioma_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroIdioma $idioma = null;

    /**
     * Teléfono de contacto del expediente.
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telefono = null;

    /**
     * Indica si el expediente pertenece al catálogo de plantillas predefinidas.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $catalogo = false;

    /**
     * Colección de cotizaciones asociadas a este expediente.
     *
     * @var Collection<int, CotizacionCotizacion>
     */
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionCotizacion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'DESC'])]
    private Collection $cotizaciones;

    /**
     * Colección de documentos adjuntos al expediente.
     *
     * @var Collection<int, CotizacionFiledocumento>
     */
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFiledocumento::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['prioridad' => 'ASC'])]
    private Collection $filedocumentos;

    /**
     * Colección de pasajeros registrados en este expediente.
     *
     * @var Collection<int, CotizacionFilepasajero>
     */
    #[ORM\OneToMany(mappedBy: 'file', targetEntity: CotizacionFilepasajero::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $filepasajeros;

    /**
     * Fecha y hora de creación del expediente (gestión automática por Gedmo Timestampable).
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $creado = null;

    /**
     * Fecha y hora de última modificación del expediente (gestión automática por Gedmo Timestampable).
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTimeInterface $modificado = null;

    /**
     * Inicializa las colecciones internas de la entidad.
     */
    public function __construct()
    {
        $this->cotizaciones   = new ArrayCollection();
        $this->filepasajeros  = new ArrayCollection();
        $this->filedocumentos = new ArrayCollection();
    }

    /**
     * Representación en formato cadena de texto de la entidad.
     *
     * @return string Retorna el nombre si existe, de lo contrario el ID formateado.
     */
    public function __toString(): string
    {
        return $this->getNombre() ?? sprintf('Id: %s.', $this->getId() ?? '');
    }

    /**
     * Callback Lifecycle de Doctrine (PostLoad).
     * Calcula automáticamente el color para el calendario al cargar la entidad desde la base de datos.
     */
    #[ORM\PostLoad]
    public function init(): void
    {
        if ($this->id !== null) {
            $this->color = $this->getColorFromId($this->id);
        }
    }

    /**
     * Genera un color en formato HEX pseudoaleatorio derivado del ID para diferenciar visualmente en calendarios.
     *
     * @param int $id Identificador de la entidad.
     * @return string Código hexadecimal del color (ej. "#a1b2c3").
     */
    public function getColorFromId(int $id): string
    {
        $h = ($id * 37) % 360; // Tono pseudoaleatorio
        $s = 60;               // Saturación %
        $v = 70;               // Brillo %
        return $this->hsvToHex($h, $s, $v);
    }

    /**
     * Convierte valores de color de espacio HSV a formato Hexadecimal.
     *
     * @param float $h Tono (Hue 0-360)
     * @param float $s Saturación (Saturation 0-100)
     * @param float $v Valor/Brillo (Value 0-100)
     * @return string Cadena de color Hexadecimal.
     */
    private function hsvToHex(float $h, float $s, float $v): string
    {
        $s /= 100;
        $v /= 100;
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;

        if ($h < 60) {
            list($r, $g, $b) = [$c, $x, 0];
        } elseif ($h < 120) {
            list($r, $g, $b) = [$x, $c, 0];
        } elseif ($h < 180) {
            list($r, $g, $b) = [0, $c, $x];
        } elseif ($h < 240) {
            list($r, $g, $b) = [0, $x, $c];
        } elseif ($h < 300) {
            list($r, $g, $b) = [$x, 0, $c];
        } else {
            list($r, $g, $b) = [$c, 0, $x];
        }

        return sprintf('#%02x%02x%02x', ($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255);
    }

    /**
     * Obtiene el identificador del expediente.
     *
     * @return int|null Identificador o null si es una instancia nueva.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Obtiene el color calculado para el calendario.
     *
     * @return string|null Código de color Hexadecimal o null.
     */
    public function getColor(): ?string
    {
        return $this->color;
    }

    /**
     * Establece el token único del expediente.
     *
     * @param string|null $token
     * @return self
     */
    public function setToken(?string $token): self
    {
        $this->token = $token;
        return $this;
    }

    /**
     * Obtiene el token del expediente.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Establece el nombre descriptivo del expediente.
     *
     * @param string|null $nombre
     * @return self
     */
    public function setNombre(?string $nombre): self
    {
        $this->nombre = $nombre;
        return $this;
    }

    /**
     * Obtiene el nombre descriptivo del expediente.
     *
     * @return string|null
     */
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    /**
     * Establece el país asociado.
     *
     * @param MaestroPais $pais
     * @return self
     */
    public function setPais(MaestroPais $pais): self
    {
        $this->pais = $pais;
        return $this;
    }

    /**
     * Obtiene el país asociado.
     *
     * @return MaestroPais|null
     */
    public function getPais(): ?MaestroPais
    {
        return $this->pais;
    }

    /**
     * Establece el idioma configurado.
     *
     * @param MaestroIdioma|null $idioma
     * @return self
     */
    public function setIdioma(?MaestroIdioma $idioma): self
    {
        $this->idioma = $idioma;
        return $this;
    }

    /**
     * Obtiene el idioma configurado.
     *
     * @return MaestroIdioma|null
     */
    public function getIdioma(): ?MaestroIdioma
    {
        return $this->idioma;
    }

    /**
     * Establece el número telefónico de contacto.
     *
     * @param string|null $telefono
     * @return self
     */
    public function setTelefono(?string $telefono): self
    {
        $this->telefono = $telefono;
        return $this;
    }

    /**
     * Obtiene el número telefónico de contacto.
     *
     * @return string|null
     */
    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    /**
     * Establece si el expediente es una plantilla de catálogo.
     *
     * @param bool $catalogo
     * @return self
     */
    public function setCatalogo(bool $catalogo): self
    {
        $this->catalogo = $catalogo;
        return $this;
    }

    /**
     * Indica si el expediente es de catálogo.
     *
     * @return bool
     */
    public function isCatalogo(): bool
    {
        return $this->catalogo;
    }

    /**
     * Agrega una cotización a la colección del expediente.
     *
     * @param CotizacionCotizacion|null $cotizacion
     * @return self
     */
    public function addCotizacion(?CotizacionCotizacion $cotizacion): self
    {
        if ($cotizacion !== null && !$this->cotizaciones->contains($cotizacion)) {
            $cotizacion->setFile($this);
            $this->cotizaciones->add($cotizacion);
        }
        return $this;
    }

    /**
     * Agrega una cotización (Soporte para Inflector en inglés / Form Type de Symfony).
     *
     * @param CotizacionCotizacion $cotizacion
     * @return self
     */
    public function addCotizacione(CotizacionCotizacion $cotizacion): self
    {
        return $this->addCotizacion($cotizacion);
    }

    /**
     * Remueve una cotización de la colección del expediente.
     *
     * @param CotizacionCotizacion $cotizacion
     * @return bool Retorna true si fue removida exitosamente.
     */
    public function removeCotizacion(CotizacionCotizacion $cotizacion): bool
    {
        return $this->cotizaciones->removeElement($cotizacion);
    }

    /**
     * Remueve una cotización (Soporte para Inflector en inglés / Form Type de Symfony).
     *
     * @param CotizacionCotizacion $cotizacion
     * @return bool
     */
    public function removeCotizacione(CotizacionCotizacion $cotizacion): bool
    {
        return $this->removeCotizacion($cotizacion);
    }

    /**
     * Obtiene la colección completa de cotizaciones del expediente.
     *
     * @return Collection<int, CotizacionCotizacion>
     */
    public function getCotizaciones(): Collection
    {
        return $this->cotizaciones;
    }

    /**
     * Agrega un pasajero al expediente.
     *
     * @param CotizacionFilepasajero $filepasajero
     * @return self
     */
    public function addFilepasajero(CotizacionFilepasajero $filepasajero): self
    {
        if (!$this->filepasajeros->contains($filepasajero)) {
            $filepasajero->setFile($this);
            $this->filepasajeros->add($filepasajero);
        }
        return $this;
    }

    /**
     * Remueve un pasajero del expediente.
     *
     * @param CotizacionFilepasajero $filepasajero
     * @return bool
     */
    public function removeFilepasajero(CotizacionFilepasajero $filepasajero): bool
    {
        return $this->filepasajeros->removeElement($filepasajero);
    }

    /**
     * Obtiene la colección de pasajeros del expediente.
     *
     * @return Collection<int, CotizacionFilepasajero>
     */
    public function getFilepasajeros(): Collection
    {
        return $this->filepasajeros;
    }

    /**
     * Agrega un documento al expediente.
     *
     * @param CotizacionFiledocumento $filedocumento
     * @return self
     */
    public function addFiledocumento(CotizacionFiledocumento $filedocumento): self
    {
        if (!$this->filedocumentos->contains($filedocumento)) {
            $filedocumento->setFile($this);
            $this->filedocumentos->add($filedocumento);
        }
        return $this;
    }

    /**
     * Remueve un documento del expediente.
     *
     * @param CotizacionFiledocumento $filedocumento
     * @return bool
     */
    public function removeFiledocumento(CotizacionFiledocumento $filedocumento): bool
    {
        return $this->filedocumentos->removeElement($filedocumento);
    }

    /**
     * Obtiene la colección de documentos del expediente.
     *
     * @return Collection<int, CotizacionFiledocumento>
     */
    public function getFiledocumentos(): Collection
    {
        return $this->filedocumentos;
    }

    /**
     * Establece la fecha de creación del expediente.
     *
     * @param DateTimeInterface|null $creado
     * @return self
     */
    public function setCreado(?DateTimeInterface $creado): self
    {
        $this->creado = $creado;
        return $this;
    }

    /**
     * Obtiene la fecha de creación del expediente.
     *
     * @return DateTimeInterface|null
     */
    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    /**
     * Establece la fecha de modificación del expediente.
     *
     * @param DateTimeInterface|null $modificado
     * @return self
     */
    public function setModificado(?DateTimeInterface $modificado): self
    {
        $this->modificado = $modificado;
        return $this;
    }

    /**
     * Obtiene la fecha de modificación del expediente.
     *
     * @return DateTimeInterface|null
     */
    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }
}