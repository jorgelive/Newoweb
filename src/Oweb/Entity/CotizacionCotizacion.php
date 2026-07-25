<?php

namespace App\Oweb\Entity;

use App\Oweb\Service\CotizacionItinerario;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad CotizacionCotizacion.
 *
 * Representa una propuesta de cotización vinculada a un expediente (CotizacionFile),
 * con itinerario, precios, comisiones, notas y servicios asociados.
 */
#[ORM\Table(name: 'cot_cotizacion')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: CotizacionCotizacionTranslation::class)]
class CotizacionCotizacion
{
    /**
     * Identificador único de la cotización.
     */
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * Colección de traducciones de la cotización.
     *
     * @var Collection<int, CotizacionCotizacionTranslation>
     */
    #[ORM\OneToMany(mappedBy: 'object', targetEntity: CotizacionCotizacionTranslation::class, cascade: ['persist', 'remove'])]
    protected Collection $translations;

    /**
     * Token de seguridad/identificación de la cotización.
     */
    #[ORM\Column(type: 'string', length: 20)]
    private ?string $token = null;

    /**
     * Token asignado para vistas/operaciones internas.
     */
    #[ORM\Column(type: 'string', length: 20)]
    private ?string $tokenoperaciones = null;

    /**
     * Nombre descriptivo de la cotización.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    /**
     * Resumen traducible de la propuesta.
     */
    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text')]
    private ?string $resumen = null;

    /**
     * Columna generada por base de datos (Virtual Column). Mantiene el resumen original sin traducir.
     */
    #[ORM\Column(
        name: 'resumenoriginal',
        type: 'text',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (`resumen`) VIRTUAL"
    )]
    private ?string $resumenoriginal = null;

    /**
     * Número total de pasajeros contemplados en la cotización.
     */
    #[ORM\Column(type: 'integer')]
    private ?int $numeropasajeros = null;

    /**
     * Porcentaje de comisión aplicable (representado como string decimal).
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private ?string $comision = '20.00';

    /**
     * Porcentaje de adelanto requerido (representado como string decimal).
     */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private ?string $adelanto = '50.00';

    /**
     * Flag para indicar si se ocultan los hoteles en los reportes/vistas.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $hoteloculto = false;

    /**
     * Flag para ocultar el precio final en el resumen.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $precioocultoresumen = false;

    /**
     * Estado actual de la cotización.
     */
    #[ORM\ManyToOne(targetEntity: CotizacionEstadocotizacion::class)]
    #[ORM\JoinColumn(name: 'estadocotizacion_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionEstadocotizacion $estadocotizacion = null;

    /**
     * Expediente (File) al que pertenece la cotización.
     */
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionFile $file = null;

    /**
     * Política de ventas y cancelación aplicable a la cotización.
     */
    #[ORM\ManyToOne(targetEntity: CotizacionCotpolitica::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(name: 'cotpolitica_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionCotpolitica $cotpolitica = null;

    /**
     * Notas o aclaraciones asociadas a la cotización.
     *
     * @var Collection<int, CotizacionCotnota>
     */
    #[ORM\ManyToMany(targetEntity: CotizacionCotnota::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinTable(name: 'cotizacion_cotnota')]
    #[ORM\JoinColumn(name: 'cotizacion_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'cotnota_id', referencedColumnName: 'id')]
    private Collection $cotnotas;

    /**
     * Servicios e itinerario incluidos en esta cotización.
     *
     * @var Collection<int, CotizacionCotservicio>
     */
    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionCotservicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechahorainicio' => 'ASC'])]
    private Collection $cotservicios;

    /**
     * Links de menú relacionados con la cotización.
     *
     * @var Collection<int, CotizacionMenulink>
     */
    #[ORM\OneToMany(
        mappedBy: 'cotizacion',
        targetEntity: CotizacionMenulink::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['posicion' => 'ASC', 'id' => 'ASC'])]
    private Collection $menulinks;

    /**
     * Almacén en memoria no mapeado a DB para las fotos de portada (poblado vía listeners).
     *
     * @see CotizacionItinerario::GetMainFoto()
     * @var Collection<int, MaestroMedio>
     */
    private Collection $portadafotos;

    /**
     * Fecha de emisión de la cotización.
     */
    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fecha = null;

    /**
     * Fecha de ingreso/llegada de los pasajeros.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaingreso = null;

    /**
     * Fecha de salida/retorno de los pasajeros.
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechasalida = null;

    /**
     * Fecha de creación del registro.
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    /**
     * Fecha de modificación del registro.
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    /**
     * Idioma/Locale utilizado en las traducciones de Gedmo.
     */
    #[Gedmo\Locale]
    private ?string $locale = null;

    /**
     * Inicializa las colecciones de la cotización.
     */
    public function __construct()
    {
        $this->cotservicios  = new ArrayCollection();
        $this->cotnotas      = new ArrayCollection();
        $this->translations  = new ArrayCollection();
        $this->portadafotos  = new ArrayCollection();
        $this->menulinks     = new ArrayCollection();
    }

    /**
     * Clona de manera profunda la cotización y sus servicios asociados.
     */
    public function __clone(): void
    {
        if ($this->id !== null) {
            $this->id = null;
            $this->setFecha(new DateTime('today'));
            $this->setCreado(null);
            $this->setModificado(null);
            $this->setToken((string) mt_rand());
            $this->setTokenoperaciones((string) mt_rand());

            $newCotservicios = new ArrayCollection();
            foreach ($this->cotservicios as $cotservicio) {
                $clone = clone $cotservicio;
                $clone->setCotizacion($this);
                $newCotservicios->add($clone);
            }
            $this->cotservicios = $newCotservicios;
        }
    }

    /**
     * Formatea la representación en cadena de texto de la cotización.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->getFile()?->isCatalogo() === true) {
            return sprintf('%s - %s', $this->getNumerocotizacion(), $this->getTitulo());
        }

        $estadoId = $this->getEstadocotizacion()?->getId();
        if ($estadoId === CotizacionEstadocotizacion::DB_VALOR_PENDIENTE || $estadoId === CotizacionEstadocotizacion::DB_VALOR_WAITING) {
            return sprintf('%s %s x%s', $this->getNumerocotizacion(), $this->getFile()?->getNombre() ?? '', $this->getNumeropasajeros() ?? 0);
        }

        return sprintf(
            '%s %s x%s (%s)',
            $this->getNumerocotizacion(),
            $this->getFile()?->getNombre() ?? '',
            $this->getNumeropasajeros() ?? 0,
            $this->getEstadocotizacion()?->getNombre() ?? ''
        );
    }

    /**
     * Genera la clave de numeración oficial de la opción de cotización.
     *
     * @return string Código formateado (ej. "OPC00123").
     */
    public function getNumerocotizacion(): string
    {
        return sprintf('OPC%05d', $this->getId() ?? 0);
    }

    /**
     * Obtiene un extracto de texto plano del resumen.
     *
     * @return string Título abreviado.
     */
    public function getTitulo(): string
    {
        return substr(str_replace('&nbsp;', '', strip_tags($this->resumen ?? '')), 0, 100) . '...';
    }

    /**
     * Establece el locale/idioma para las traducciones de Gedmo.
     *
     * @param string|null $locale
     * @return self
     */
    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Obtiene las traducciones asignadas.
     *
     * @return Collection<int, CotizacionCotizacionTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    /**
     * Agrega una traducción a la cotización.
     *
     * @param CotizacionCotizacionTranslation $translation
     * @return void
     */
    public function addTranslation(CotizacionCotizacionTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
    }

    /**
     * Obtiene el identificador único.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Establece el token.
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
     * Obtiene el token.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Establece el token de operaciones.
     *
     * @param string|null $tokenoperaciones
     * @return self
     */
    public function setTokenoperaciones(?string $tokenoperaciones): self
    {
        $this->tokenoperaciones = $tokenoperaciones;
        return $this;
    }

    /**
     * Obtiene el token de operaciones.
     *
     * @return string|null
     */
    public function getTokenoperaciones(): ?string
    {
        return $this->tokenoperaciones;
    }

    /**
     * Retorna el código formateado con guion.
     *
     * @return string|null Ejemplo "OPC-00123" o null si no tiene ID.
     */
    public function getCodigo(): ?string
    {
        return $this->id ? 'OPC-' . sprintf('%05d', $this->id) : null;
    }

    /**
     * Establece el nombre.
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
     * Obtiene el nombre.
     *
     * @return string|null
     */
    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    /**
     * Establece la cantidad de pasajeros.
     *
     * @param int|null $numeropasajeros
     * @return self
     */
    public function setNumeropasajeros(?int $numeropasajeros): self
    {
        $this->numeropasajeros = $numeropasajeros;
        return $this;
    }

    /**
     * Obtiene la cantidad de pasajeros.
     *
     * @return int|null
     */
    public function getNumeropasajeros(): ?int
    {
        return $this->numeropasajeros;
    }

    /**
     * Establece la fecha de creación.
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
     * Obtiene la fecha de creación.
     *
     * @return DateTimeInterface|null
     */
    public function getCreado(): ?DateTimeInterface
    {
        return $this->creado;
    }

    /**
     * Establece la fecha de modificación.
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
     * Obtiene la fecha de modificación.
     *
     * @return DateTimeInterface|null
     */
    public function getModificado(): ?DateTimeInterface
    {
        return $this->modificado;
    }

    /**
     * Establece el estado de la cotización.
     *
     * @param CotizacionEstadocotizacion|null $estadocotizacion
     * @return self
     */
    public function setEstadocotizacion(?CotizacionEstadocotizacion $estadocotizacion): self
    {
        $this->estadocotizacion = $estadocotizacion;
        return $this;
    }

    /**
     * Obtiene el estado de la cotización.
     *
     * @return CotizacionEstadocotizacion|null
     */
    public function getEstadocotizacion(): ?CotizacionEstadocotizacion
    {
        return $this->estadocotizacion;
    }

    /**
     * Asigna el expediente (File) padre.
     *
     * @param CotizacionFile|null $file
     * @return self
     */
    public function setFile(?CotizacionFile $file): self
    {
        $this->file = $file;
        return $this;
    }

    /**
     * Obtiene el expediente (File) padre.
     *
     * @return CotizacionFile|null
     */
    public function getFile(): ?CotizacionFile
    {
        return $this->file;
    }

    /**
     * Agrega un servicio a la cotización.
     *
     * @param CotizacionCotservicio $cotservicio
     * @return self
     */
    public function addCotservicio(CotizacionCotservicio $cotservicio): self
    {
        if (!$this->cotservicios->contains($cotservicio)) {
            $cotservicio->setCotizacion($this);
            $this->cotservicios->add($cotservicio);
        }
        return $this;
    }

    /**
     * Remueve un servicio de la cotización.
     *
     * @param CotizacionCotservicio $cotservicio
     * @return void
     */
    public function removeCotservicio(CotizacionCotservicio $cotservicio): void
    {
        $this->cotservicios->removeElement($cotservicio);
    }

    /**
     * Obtiene la lista de servicios.
     *
     * @return Collection<int, CotizacionCotservicio>
     */
    public function getCotservicios(): Collection
    {
        return $this->cotservicios;
    }

    /**
     * Oculta o muestra hoteles en reportes.
     *
     * @param bool $hoteloculto
     * @return self
     */
    public function setHoteloculto(bool $hoteloculto): self
    {
        $this->hoteloculto = $hoteloculto;
        return $this;
    }

    /**
     * Retorna si los hoteles están ocultos.
     *
     * @return bool
     */
    public function isHoteloculto(): bool
    {
        return $this->hoteloculto;
    }

    /**
     * Oculta o muestra el precio en el resumen.
     *
     * @param bool $precioocultoresumen
     * @return self
     */
    public function setPrecioocultoresumen(bool $precioocultoresumen): self
    {
        $this->precioocultoresumen = $precioocultoresumen;
        return $this;
    }

    /**
     * Retorna si el precio está oculto en el resumen.
     *
     * @return bool
     */
    public function isPrecioocultoresumen(): bool
    {
        return $this->precioocultoresumen;
    }

    /**
     * Agrega una foto de portada al repositorio volátil en memoria.
     *
     * @param MaestroMedio $portadafoto
     * @return self
     */
    public function addPortadafoto(MaestroMedio $portadafoto): self
    {
        if (!isset($this->portadafotos)) {
            $this->portadafotos = new ArrayCollection();
        }
        if (!$this->portadafotos->contains($portadafoto)) {
            $this->portadafotos->add($portadafoto);
        }
        return $this;
    }

    /**
     * Obtiene la colección en memoria de fotos de portada.
     *
     * @return Collection<int, MaestroMedio>
     */
    public function getPortadafotos(): Collection
    {
        if (!isset($this->portadafotos)) {
            $this->portadafotos = new ArrayCollection();
        }
        return $this->portadafotos;
    }

    /**
     * Obtiene la colección de links de menú.
     *
     * @return Collection<int, CotizacionMenulink>
     */
    public function getMenulinks(): Collection
    {
        return $this->menulinks;
    }

    /**
     * Elimina una relación de menú.
     *
     * @param CotizacionMenulink $menulink
     * @return bool
     */
    public function removeMenulink(CotizacionMenulink $menulink): bool
    {
        if (($menu = $menulink->getMenu()) !== null) {
            $menu->getMenulinks()->removeElement($menulink);
        }
        return $this->menulinks->removeElement($menulink);
    }

    /**
     * Asocia la cotización con una entrada de menú.
     *
     * @param CotizacionMenu $menu
     * @param int|null $posicion
     * @return self
     */
    public function addMenulink(CotizacionMenu $menu, ?int $posicion = null): self
    {
        foreach ($this->menulinks as $link) {
            if ($link->getMenu() === $menu) {
                if ($posicion !== null) {
                    $link->setPosicion($posicion);
                }
                if (!$menu->getMenulinks()->contains($link)) {
                    $menu->getMenulinks()->add($link);
                }
                return $this;
            }
        }

        $link = (new CotizacionMenulink())
            ->setCotizacion($this)
            ->setMenu($menu)
            ->setPosicion($posicion ?? $this->nextPositionFor($menu));

        $this->menulinks->add($link);
        if (!$menu->getMenulinks()->contains($link)) {
            $menu->getMenulinks()->add($link);
        }
        return $this;
    }

    /**
     * Calcula la siguiente posición disponible dentro de un menú.
     *
     * @param CotizacionMenu $menu
     * @return int
     */
    private function nextPositionFor(CotizacionMenu $menu): int
    {
        $max = 0;
        foreach ($this->menulinks as $link) {
            if ($link->getMenu() === $menu && $link->getPosicion() > $max) {
                $max = $link->getPosicion();
            }
        }
        return $max + 1;
    }

    /**
     * Establece el porcentaje de comisión.
     *
     * @param string|null $comision
     * @return self
     */
    public function setComision(?string $comision): self
    {
        $this->comision = $comision;
        return $this;
    }

    /**
     * Obtiene el porcentaje de comisión.
     *
     * @return string|null
     */
    public function getComision(): ?string
    {
        return $this->comision;
    }

    /**
     * Establece el porcentaje de adelanto.
     *
     * @param string|null $adelanto
     * @return self
     */
    public function setAdelanto(?string $adelanto): self
    {
        $this->adelanto = $adelanto;
        return $this;
    }

    /**
     * Obtiene el porcentaje de adelanto.
     *
     * @return string|null
     */
    public function getAdelanto(): ?string
    {
        return $this->adelanto;
    }

    /**
     * Establece las políticas comerciales.
     *
     * @param CotizacionCotpolitica|null $cotpolitica
     * @return self
     */
    public function setCotpolitica(?CotizacionCotpolitica $cotpolitica): self
    {
        $this->cotpolitica = $cotpolitica;
        return $this;
    }

    /**
     * Obtiene las políticas comerciales.
     *
     * @return CotizacionCotpolitica|null
     */
    public function getCotpolitica(): ?CotizacionCotpolitica
    {
        return $this->cotpolitica;
    }

    /**
     * Agrega una nota a la cotización.
     *
     * @param CotizacionCotnota $cotnota
     * @return self
     */
    public function addCotnota(CotizacionCotnota $cotnota): self
    {
        if (!$this->cotnotas->contains($cotnota)) {
            $this->cotnotas->add($cotnota);
        }
        return $this;
    }

    /**
     * Remueve una nota de la cotización.
     *
     * @param CotizacionCotnota $cotnota
     * @return bool
     */
    public function removeCotnota(CotizacionCotnota $cotnota): bool
    {
        return $this->cotnotas->removeElement($cotnota);
    }

    /**
     * Obtiene las notas asignadas.
     *
     * @return Collection<int, CotizacionCotnota>
     */
    public function getCotnotas(): Collection
    {
        return $this->cotnotas;
    }

    /**
     * Establece el resumen de la propuesta.
     *
     * @param string|null $resumen
     * @return self
     */
    public function setResumen(?string $resumen = null): self
    {
        $this->resumen = $resumen;
        return $this;
    }

    /**
     * Obtiene el resumen de la propuesta.
     *
     * @return string|null
     */
    public function getResumen(): ?string
    {
        return $this->resumen;
    }

    /**
     * Obtiene el resumen original almacenado en la columna virtual.
     *
     * @return string|null
     */
    public function getResumenoriginal(): ?string
    {
        return $this->resumenoriginal;
    }

    /**
     * Establece la fecha de la cotización.
     *
     * @param DateTimeInterface $fecha
     * @return self
     */
    public function setFecha(DateTimeInterface $fecha): self
    {
        $this->fecha = $fecha;
        return $this;
    }

    /**
     * Obtiene la fecha de la cotización.
     *
     * @return DateTimeInterface|null
     */
    public function getFecha(): ?DateTimeInterface
    {
        return $this->fecha;
    }

    /**
     * Establece la fecha de ingreso/llegada.
     *
     * @param DateTimeInterface|null $fechaingreso
     * @return self
     */
    public function setFechaingreso(?DateTimeInterface $fechaingreso): self
    {
        $this->fechaingreso = $fechaingreso;
        return $this;
    }

    /**
     * Obtiene la fecha de ingreso/llegada.
     *
     * @return DateTimeInterface|null
     */
    public function getFechaingreso(): ?DateTimeInterface
    {
        return $this->fechaingreso;
    }

    /**
     * Establece la fecha de salida/retorno.
     *
     * @param DateTimeInterface|null $fechasalida
     * @return self
     */
    public function setFechasalida(?DateTimeInterface $fechasalida): self
    {
        $this->fechasalida = $fechasalida;
        return $this;
    }

    /**
     * Obtiene la fecha de salida/retorno.
     *
     * @return DateTimeInterface|null
     */
    public function getFechasalida(): ?DateTimeInterface
    {
        return $this->fechasalida;
    }
}