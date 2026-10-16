<?php

declare(strict_types=1);

namespace App\Front\Comun\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\AutoTranslate;
use App\Entity\Trait\AutoTranslateControlTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un álbum de EXPERIENCIAS: las fotos y vídeos de un viaje ya operado, para la web.
 *
 * **Va aparte del cotizador y de los expedientes** (Jorge, 07/10/2026): el catálogo es una
 * propuesta genérica y el expediente es la venta; las fotos de clientes llegan DESPUÉS de operar
 * y hoy no estaban en ninguna parte. Los archivos de un expediente (DNI, billetes) son privados y
 * nunca alimentan la web. Ver docs/PlanWebPublica.md, Fase 3, y docs/WebPublica.md.
 *
 * 🔒 **`publicado` exige `autorizado`.** Son, sobre todo, viajes escolares con menores: enseñar su
 * imagen sin la autorización firmada de los padres es un riesgo legal (Ley 29733). La casilla la
 * marca quien tiene esa autorización; sin ella el álbum no sale, y el validador lo impide aunque
 * alguien intente publicarlo por la API.
 */
#[ApiResource(
    shortName: 'ExperienciaAlbum',
    operations: [
        new GetCollection(
            order: ['orden' => 'ASC', 'createdAt' => 'DESC'],
            normalizationContext: ['groups' => ['experiencia:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
        ),
        new Get(
            normalizationContext: ['groups' => ['experiencia:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_SHOW . "')",
        ),
        new Post(
            normalizationContext: ['groups' => ['experiencia:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['experiencia:write']],
            securityPostDenormalize: "is_granted('" . Roles::RESERVAS_WRITE . "')",
        ),
        new Patch(
            normalizationContext: ['groups' => ['experiencia:read', 'timestamp:read']],
            denormalizationContext: ['groups' => ['experiencia:write']],
            security: "is_granted('" . Roles::RESERVAS_WRITE . "')",
        ),
        new Delete(
            normalizationContext: ['groups' => ['experiencia:read', 'timestamp:read']],
            security: "is_granted('" . Roles::RESERVAS_DELETE . "')",
        ),
    ],
    routePrefix: '/front',
)]
#[ORM\Entity]
#[ORM\Table(name: 'front_experiencia_album')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Ya hay otro álbum con esa dirección.')]
class ExperienciaAlbum
{
    use IdTrait;
    use TimestampTrait;
    use AutoTranslateControlTrait;

    /** El id además del `@id`: la vista de util construye con él las URLs de sus medios. */
    #[Groups(['experiencia:read'])]
    public function getId(): ?Uuid
    {
        return $this->id;
    }

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $titulo = [];

    /** @var list<array{language?: string, content?: string|null}> */
    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[AutoTranslate(sourceLanguage: 'es', format: 'text')]
    #[ORM\Column(type: 'json')]
    private array $texto = [];

    /** Dónde fue: «Punta Cana», «Cusco y Machu Picchu». Texto libre, se enseña tal cual. */
    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[Assert\Length(max: 120)]
    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $destino = null;

    /** Cuándo fue el viaje: ordena y fecha el álbum en la web («septiembre de 2026»). */
    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $fecha = null;

    /** Tramo de la URL: `/experiencias/{slug}`. Obligatorio para publicar. */
    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[Assert\Length(max: 80)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Sólo minúsculas, números y guiones (ej.: promo-punta-cana-2026).')]
    #[ORM\Column(type: 'string', length: 80, unique: true, nullable: true)]
    private ?string $slug = null;

    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $orden = 0;

    /** Hay autorización firmada para publicar las imágenes. Ver el docblock de la clase. */
    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $autorizado = false;

    #[Groups(['experiencia:read', 'experiencia:write'])]
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $publicado = false;

    /** @var Collection<int, ExperienciaMedio> */
    #[Groups(['experiencia:read'])]
    #[ORM\OneToMany(targetEntity: ExperienciaMedio::class, mappedBy: 'album', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['orden' => 'ASC', 'createdAt' => 'ASC'])]
    private Collection $medios;

    public function __construct()
    {
        $this->initializeId();
        $this->medios = new ArrayCollection();
    }

    /**
     * Publicar exige autorización y dirección. Va como validación —y no sólo como casilla
     * deshabilitada en util— porque la API se puede llamar sin pasar por la pantalla.
     */
    #[Assert\Callback]
    public function validarPublicacion(ExecutionContextInterface $context): void
    {
        if (!$this->publicado) {
            return;
        }

        if (!$this->autorizado) {
            $context->buildViolation('Para publicar el álbum hace falta la autorización de las familias: marca «autorizado» cuando la tengas firmada.')
                ->atPath('publicado')
                ->addViolation();
        }

        if ($this->slug === null || $this->slug === '') {
            $context->buildViolation('Para publicar el álbum hace falta su dirección (slug).')
                ->atPath('slug')
                ->addViolation();
        }
    }

    /** ¿Lo puede enseñar la web? Lo mismo que exige el validador, para quien lee sin validar. */
    public function esVisibleEnLaWeb(): bool
    {
        return $this->publicado && $this->autorizado && $this->slug !== null && $this->slug !== '';
    }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getTitulo(): array { return $this->titulo; }
    /** @param list<array{language?: string, content?: string|null}> $titulo */
    public function setTitulo(array $titulo): self { $this->titulo = $titulo; return $this; }

    /** @return list<array{language?: string, content?: string|null}> */
    public function getTexto(): array { return $this->texto; }
    /** @param list<array{language?: string, content?: string|null}> $texto */
    public function setTexto(array $texto): self { $this->texto = $texto; return $this; }

    public function getDestino(): ?string { return $this->destino; }
    public function setDestino(?string $destino): self { $this->destino = $destino !== null && trim($destino) !== '' ? trim($destino) : null; return $this; }

    public function getFecha(): ?DateTimeImmutable { return $this->fecha; }
    public function setFecha(?DateTimeImmutable $fecha): self { $this->fecha = $fecha; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = $slug !== null && trim($slug) !== '' ? trim($slug) : null; return $this; }

    public function getOrden(): int { return $this->orden; }
    public function setOrden(int $orden): self { $this->orden = $orden; return $this; }

    public function isAutorizado(): bool { return $this->autorizado; }
    public function setAutorizado(bool $autorizado): self { $this->autorizado = $autorizado; return $this; }

    public function isPublicado(): bool { return $this->publicado; }
    public function setPublicado(bool $publicado): self { $this->publicado = $publicado; return $this; }

    /** @return Collection<int, ExperienciaMedio> */
    public function getMedios(): Collection { return $this->medios; }

    public function addMedio(ExperienciaMedio $medio): self
    {
        if (!$this->medios->contains($medio)) {
            $this->medios->add($medio);
            $medio->setAlbum($this);
        }

        return $this;
    }

    public function removeMedio(ExperienciaMedio $medio): self
    {
        $this->medios->removeElement($medio);

        return $this;
    }

    /**
     * La foto de portada: la marcada o, si no hay, la primera foto. Un álbum sin fotos (sólo
     * vídeos) no tiene portada propia; la web usa la miniatura del primer vídeo de YouTube.
     */
    public function portada(): ?ExperienciaMedio
    {
        $primera = null;

        foreach ($this->medios as $medio) {
            if ($medio->esFoto() && $medio->getImageName() !== null) {
                if ($medio->isEsPortada()) {
                    return $medio;
                }
                $primera ??= $medio;
            }
        }

        return $primera;
    }

    public function __toString(): string
    {
        foreach ($this->titulo as $fila) {
            if (($fila['language'] ?? null) === 'es' && ($fila['content'] ?? '') !== '') {
                return (string) $fila['content'];
            }
        }

        return 'Álbum sin título';
    }
}
