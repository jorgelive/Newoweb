<?php

namespace App\Entity;

use App\Entity\CotizacionCotizacionTranslation;
use App\Entity\CotizacionCotnota;
use App\Entity\CotizacionCotpolitica;
use App\Entity\CotizacionCotservicio;
use App\Entity\CotizacionEstadocotizacion;
use App\Entity\CotizacionFile;
use App\Entity\MaestroMedio; // solo para tipo de $portadafotos (no mapeado)
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Table(name: 'cot_cotizacion')]
#[ORM\Entity]
#[Gedmo\TranslationEntity(class: CotizacionCotizacionTranslation::class)]
class CotizacionCotizacion
{
    #[ORM\Column(type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /** @var Collection<int, CotizacionCotizacionTranslation> */
    #[ORM\OneToMany(mappedBy: 'object', targetEntity: CotizacionCotizacionTranslation::class, cascade: ['persist', 'remove'])]
    protected Collection $translations;

    #[ORM\Column(type: 'string', length: 20)]
    private ?string $token = null;

    #[ORM\Column(type: 'string', length: 20)]
    private ?string $tokenoperaciones = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $nombre = null;

    // Translatable SIEMPRE con definición de columna (consigna)
    #[Gedmo\Translatable]
    #[ORM\Column(type: 'text')]
    private ?string $resumen = null;

    // Columna generada en BD; se mapea como read-only
    #[ORM\Column(type: 'text', insertable: false, updatable: false, columnDefinition: 'longtext AS (resumen) VIRTUAL NULL', generated: 'ALWAYS')]
    private ?string $resumenoriginal = null;

    #[ORM\Column(type: 'integer')]
    private ?int $numeropasajeros = null;

    // Decimales como string para evitar pérdida de precisión
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private ?string $comision = '20.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: false)]
    private ?string $adelanto = '50.00';

    // Flags visibles en UI/plantillas
    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $hoteloculto = false;

    #[ORM\Column(type: 'boolean', options: ['default' => 0])]
    private bool $precioocultoresumen = false;

    #[ORM\ManyToOne(targetEntity: CotizacionEstadocotizacion::class)]
    #[ORM\JoinColumn(name: 'estadocotizacion_id', referencedColumnName: 'id', nullable: false)]
    protected ?CotizacionEstadocotizacion $estadocotizacion = null;

    #[ORM\ManyToOne(targetEntity: CotizacionFile::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionFile $file = null;

    #[ORM\ManyToOne(targetEntity: CotizacionCotpolitica::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinColumn(name: 'cotpolitica_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionCotpolitica $cotpolitica = null;

    /** @var Collection<int, CotizacionCotnota> */
    #[ORM\ManyToMany(targetEntity: CotizacionCotnota::class, inversedBy: 'cotizaciones')]
    #[ORM\JoinTable(name: 'cotizacion_cotnota')]
    #[ORM\JoinColumn(name: 'cotizacion_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'cotnota_id', referencedColumnName: 'id')]
    private Collection $cotnotas;

    /** @var Collection<int, CotizacionCotservicio> */
    #[ORM\OneToMany(mappedBy: 'cotizacion', targetEntity: CotizacionCotservicio::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fechahorainicio' => 'ASC'])]
    private Collection $cotservicios;

    /** @var Collection<int, CotizacionMenulink> */
    #[ORM\OneToMany(
        mappedBy: 'cotizacion',
        targetEntity: CotizacionMenulink::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['posicion' => 'ASC', 'id' => 'ASC'])]
    private Collection $menulinks;

    /**
     * Almacén no mapeado (lo llena un listener postLoad)
     * @see CotizacionItinerario::GetMainFoto()
     * @var Collection<int, MaestroMedio>
     */
    private Collection $portadafotos;

    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $fecha = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechaingreso = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?DateTimeInterface $fechasalida = null;

    // Timestampable NO NULL (consigna)
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $creado = null;

    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?DateTimeInterface $modificado = null;

    #[Gedmo\Locale]
    private ?string $locale = null;

    public function __construct()
    {
        $this->cotservicios  = new ArrayCollection();
        $this->cotnotas      = new ArrayCollection();
        $this->translations  = new ArrayCollection();
        $this->portadafotos  = new ArrayCollection();
        $this->menulinks = new ArrayCollection();
    }

    public function __clone(): void
    {
        if ($this->id) {
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

    public function __toString(): string
    {
        // Null-safe para evitar notices en listados
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

    public function getNumerocotizacion(): string
    {
        return sprintf('OPC%05d', $this->getId() ?? 0);
    }

    public function getTitulo(): string
    {
        return substr(str_replace('&nbsp;', '', strip_tags($this->resumen ?? '')), 0, 100) . '...';
    }

    public function setLocale(?string $locale): self { $this->locale = $locale; return $this; }

    /** @return Collection<int, CotizacionCotizacionTranslation> */
    public function getTranslations(): Collection { return $this->translations; }

    public function addTranslation(CotizacionCotizacionTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
    }

    public function getId(): ?int { return $this->id; }

    public function setToken(?string $token): self { $this->token = $token; return $this; }
    public function getToken(): ?string { return $this->token; }

    public function setTokenoperaciones(?string $tokenoperaciones): self { $this->tokenoperaciones = $tokenoperaciones; return $this; }
    public function getTokenoperaciones(): ?string { return $this->tokenoperaciones; }

    public function getCodigo(): ?string
    {
        return $this->id ? 'OPC-' . sprintf('%05d', $this->id) : null;
    }

    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }
    public function getNombre(): ?string { return $this->nombre; }

    public function setNumeropasajeros(?int $numeropasajeros): self { $this->numeropasajeros = $numeropasajeros; return $this; }
    public function getNumeropasajeros(): ?int { return $this->numeropasajeros; }

    public function setCreado(?DateTimeInterface $creado): self { $this->creado = $creado; return $this; }
    public function getCreado(): ?DateTimeInterface { return $this->creado; }

    public function setModificado(?DateTimeInterface $modificado): self { $this->modificado = $modificado; return $this; }
    public function getModificado(): ?DateTimeInterface { return $this->modificado; }

    public function setEstadocotizacion(?CotizacionEstadocotizacion $estadocotizacion): self
    { $this->estadocotizacion = $estadocotizacion; return $this; }
    public function getEstadocotizacion(): ?CotizacionEstadocotizacion
    { return $this->estadocotizacion; }

    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }
    public function getFile(): ?CotizacionFile { return $this->file; }

    public function addCotservicio(CotizacionCotservicio $cotservicio): self
    {
        $cotservicio->setCotizacion($this);
        $this->cotservicios->add($cotservicio);
        return $this;
    }
    public function removeCotservicio(CotizacionCotservicio $cotservicio): void
    { $this->cotservicios->removeElement($cotservicio); }
    /** @return Collection<int, CotizacionCotservicio> */
    public function getCotservicios(): Collection { return $this->cotservicios; }

    public function setHoteloculto(bool $hoteloculto): self { $this->hoteloculto = $hoteloculto; return $this; }
    public function isHoteloculto(): bool { return $this->hoteloculto; }

    public function setPrecioocultoresumen(bool $precioocultoresumen): self { $this->precioocultoresumen = $precioocultoresumen; return $this; }
    public function isPrecioocultoresumen(): bool { return $this->precioocultoresumen; }

    public function addPortadafoto(MaestroMedio $portadafoto): self
    {
        // no mapeado: inicializar si llega por hydration manual
        if (!isset($this->portadafotos)) {
            $this->portadafotos = new ArrayCollection();
        }
        $this->portadafotos->add($portadafoto);
        return $this;
    }
    /** @return Collection<int, MaestroMedio> */
    public function getPortadafotos(): Collection
    {
        if (!isset($this->portadafotos)) {
            $this->portadafotos = new ArrayCollection();
        }
        return $this->portadafotos;
    }

    /** @return Collection<int, CotizacionMenulink> */
    public function getMenulinks(): Collection
    {
        return $this->menulinks;
    }

    public function removeMenulink(CotizacionMenulink $menulink): bool
    {
        if (($menu = $menulink->getMenu()) !== null) {
            $menu->getMenulinks()->removeElement($menulink);
        }
        return $this->menulinks->removeElement($menulink);
    }

    public function addMenulink(CotizacionMenu $menu, ?int $posicion = null): self
    {
        foreach ($this->menulinks as $link) {
            if ($link->getMenu() === $menu) {
                if ($posicion !== null) { $link->setPosicion($posicion); }
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

    public function setComision(?string $comision): self { $this->comision = $comision; return $this; }
    public function getComision(): ?string { return $this->comision; }

    public function setAdelanto(?string $adelanto): self { $this->adelanto = $adelanto; return $this; }
    public function getAdelanto(): ?string { return $this->adelanto; }

    public function setCotpolitica(?CotizacionCotpolitica $cotpolitica): self
    { $this->cotpolitica = $cotpolitica; return $this; }
    public function getCotpolitica(): ?CotizacionCotpolitica
    { return $this->cotpolitica; }

    public function addCotnota(CotizacionCotnota $cotnota): self
    {
        // owner side: sin by_reference=false
        $this->cotnotas->add($cotnota);
        return $this;
    }
    public function removeCotnota(CotizacionCotnota $cotnota): bool
    { return $this->cotnotas->removeElement($cotnota); }
    /** @return Collection<int, CotizacionCotnota> */
    public function getCotnotas(): Collection { return $this->cotnotas; }

    public function setResumen(?string $resumen = null): self { $this->resumen = $resumen; return $this; }
    public function getResumen(): ?string { return $this->resumen; }
    public function getResumenoriginal(): ?string { return $this->resumenoriginal; }

    public function setFecha(DateTimeInterface $fecha): self { $this->fecha = $fecha; return $this; }
    public function getFecha(): ?DateTimeInterface { return $this->fecha; }

    public function setFechaingreso(?DateTimeInterface $fechaingreso): self { $this->fechaingreso = $fechaingreso; return $this; }
    public function getFechaingreso(): ?DateTimeInterface { return $this->fechaingreso; }

    public function setFechasalida(?DateTimeInterface $fechasalida): self { $this->fechasalida = $fechasalida; return $this; }
    public function getFechasalida(): ?DateTimeInterface { return $this->fechasalida; }
}
