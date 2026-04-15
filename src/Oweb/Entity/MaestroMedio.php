<?php

namespace App\Oweb\Entity;

use App\Oweb\Entity\Trait\MainArchivoTrait;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Entidad MaestroMedio.
 * Gestiona el almacenamiento de datos e información meta de los archivos multimedia.
 * * @ORM\HasLifecycleCallbacks
 */
#[ORM\Table(name: 'mae_medio')]
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[Gedmo\TranslationEntity(class: 'App\Oweb\Entity\MaestroMedioTranslation')]
class MaestroMedio
{
    use MainArchivoTrait;

    /**
     * Ruta base de la aplicación donde se depositarán los archivos cargados.
     * * @var string
     */
    private $path = '/carga/maestromedio';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * Colección de traducciones del registro actual.
     * * @var Collection<int, MaestroMedioTranslation>
     */
    #[ORM\OneToMany(targetEntity: MaestroMedioTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'])]
    protected Collection $translations;

    /**
     * Título o etiqueta descriptiva del archivo multimedia.
     * * @var string|null
     */
    #[Gedmo\Translatable]
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $titulo = null;

    /**
     * Relación con la clase/categoría a la que pertenece este medio.
     * (Inicializado en null para evitar excepciones Typed Property Before Initialization).
     * * @var MaestroClasemedio|null
     */
    #[ORM\ManyToOne(targetEntity: MaestroClasemedio::class, inversedBy: 'medios')]
    #[ORM\JoinColumn(name: 'clasemedio_id', referencedColumnName: 'id', nullable: true)]
    protected ?MaestroClasemedio $clasemedio = null;

    /**
     * Fecha y hora en la que se creó el registro.
     * * @var DateTime|null
     */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $creado = null;

    /**
     * Fecha y hora de la última modificación del registro.
     * * @var DateTime|null
     */
    #[Gedmo\Timestampable(on: 'update')]
    #[ORM\Column(type: 'datetime')]
    private ?DateTime $modificado = null;

    /**
     * Idioma temporal cargado en memoria para lectura o escritura de traducciones.
     * * @var string|null
     */
    #[Gedmo\Locale]
    private ?string $locale = null;

    /**
     * Constructor de la entidad.
     * Garantiza que las relaciones OneToMany sean instancias de ArrayCollection al crear un nuevo registro.
     */
    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    /**
     * Define el idioma (locale) actual sobre el que operará la entidad al persistir traducciones.
     * * @param string|null $locale Código ISO del idioma (ej. 'es', 'en').
     * @return self
     */
    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Retorna todas las traducciones asociadas a este registro multimedia.
     * Es utilizado principalmente por la extensión Gedmo Translatable.
     * * @return Collection
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    /**
     * Asocia un nuevo objeto de traducción a esta entidad principal.
     * Esencial para registrar nuevos idiomas mediante la interfaz sin perder la relación bidireccional.
     * * @param MaestroMedioTranslation $translation El objeto de traducción específico.
     * @return void
     */
    public function addTranslation(MaestroMedioTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations[] = $translation;
            $translation->setObject($this);
        }
    }

    /**
     * Convierte el objeto en una representación en cadena (String).
     * Crítico para Sonata Admin y visualizaciones en Twig donde se imprime el objeto directamente (Ej: breadcrumbs).
     * * @return string
     */
    public function __toString(): string
    {
        if(empty($this->getClasemedio()) || empty($this->getNombre())){
            return sprintf("Id: %s.", $this->getId() ?? 'Nuevo');
        }
        return sprintf('%s: %s', $this->getClasemedio()->getNombre(), $this->getNombre());
    }

    /**
     * Obtiene el identificador único autoincremental de la base de datos.
     * * @return int|null Retorna nulo si la entidad aún no ha sido persistida (flush).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Asigna la clase o categoría correspondiente para este medio.
     * * @param MaestroClasemedio|null $clasemedio La entidad relacionada que categoriza el medio.
     * @return self
     */
    public function setClasemedio(?MaestroClasemedio $clasemedio): self
    {
        $this->clasemedio = $clasemedio;

        return $this;
    }

    /**
     * Retorna la categoría configurada del medio.
     * * @return MaestroClasemedio|null
     */
    public function getClasemedio(): ?MaestroClasemedio
    {
        return $this->clasemedio;
    }

    /**
     * Asigna manualmente la fecha de creación del registro.
     * Generalmente es manejado automáticamente por la anotación Timestampable.
     * * @param DateTime|null $creado Instancia de DateTime.
     * @return self
     */
    public function setCreado(?DateTime $creado): self
    {
        $this->creado = $creado;

        return $this;
    }

    /**
     * Obtiene la fecha exacta de creación del registro en base de datos.
     * * @return DateTime|null
     */
    public function getCreado(): ?DateTime
    {
        return $this->creado;
    }

    /**
     * Actualiza la fecha de modificación del registro.
     * * @param DateTime|null $modificado Instancia de DateTime.
     * @return self
     */
    public function setModificado(?DateTime $modificado): self
    {
        $this->modificado = $modificado;

        return $this;
    }

    /**
     * Obtiene la fecha de la última modificación realizada sobre el registro.
     * * @return DateTime|null
     */
    public function getModificado(): ?DateTime
    {
        return $this->modificado;
    }

    /**
     * Define el título o nombre con el que se mostrará este elemento al usuario.
     * * @param string|null $titulo Nombre humano del registro.
     * @return self
     */
    public function setTitulo(?string $titulo): self
    {
        $this->titulo = $titulo;

        return $this;
    }

    /**
     * Obtiene el título configurado del elemento multimedia.
     * * @return string|null
     */
    public function getTitulo(): ?string
    {
        return $this->titulo;
    }
}