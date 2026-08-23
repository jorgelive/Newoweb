<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Que esta persona pertenece a este subgrupo.
 *
 * ## Por qué es una entidad y no un `ManyToMany`
 *
 * Porque lleva datos propios. `esJefe` es un atributo de **la pertenencia**, no de la persona ni
 * del grupo: alguien lidera el grupo 5 y es miembro raso del salón B. Doctrine no deja colgar
 * columnas de la tabla de unión de un `ManyToMany`, así que en cuanto hay un solo campo ahí, deja
 * de servir.
 *
 * ## ⚠️ La coherencia de expediente no la impone la base
 *
 * El pasajero cuelga de un expediente y el grupo también, pero **esta tabla no tiene `file`** —sería
 * una tercera copia del mismo hecho, y de las que se contradicen—. Nada a nivel de esquema impide
 * unir un pasajero del expediente A con un grupo del B.
 *
 * Lo cierra {@see self::validarMismoExpediente()}, un callback de ciclo de vida. Es un invariante
 * que no se rompe nunca a mano y que un importador rompe en lote.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_pasajero_grupo')]
#[ORM\UniqueConstraint(name: 'uniq_pasajero_grupo', columns: ['pasajero_id', 'grupo_id'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionPasajeroGrupo
{
    use IdTrait;
    use TimestampTrait;

    #[Assert\NotNull]
    #[ORM\ManyToOne(targetEntity: CotizacionFilepasajero::class, inversedBy: 'pertenencias')]
    #[ORM\JoinColumn(name: 'pasajero_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFilepasajero $pasajero = null;

    #[Assert\NotNull]
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: CotizacionFileGrupo::class, inversedBy: 'miembros')]
    #[ORM\JoinColumn(name: 'grupo_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFileGrupo $grupo = null;

    /**
     * ¿Lidera este grupo?
     *
     * De aquí cuelga que, al buscarse por documento, además de lo suyo vea lo de su grupo. Va en la
     * pertenencia y no en la persona por lo dicho arriba: se lidera **un** grupo, no en general.
     */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\Column(name: 'es_jefe', type: 'boolean', options: ['default' => false])]
    private bool $esJefe = false;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        return sprintf('%s → %s', $this->pasajero?->getNombre() ?? '—', $this->grupo?->getEtiqueta() ?? '—');
    }

    /**
     * El pasajero y el grupo tienen que ser del MISMO expediente.
     *
     * Sin esto, un importador con un id mal cruzado mete a media escuela en los grupos de otro
     * viaje, y nada lo denuncia: las dos FK son válidas por separado.
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validarMismoExpediente(): void
    {
        $delPasajero = $this->pasajero?->getFile()?->getId();
        $delGrupo = $this->grupo?->getFile()?->getId();

        if ($delPasajero !== null && $delGrupo !== null && !$delPasajero->equals($delGrupo)) {
            throw new \DomainException(sprintf(
                'El pasajero y el grupo «%s» son de expedientes distintos.',
                $this->grupo->getEtiqueta(),
            ));
        }
    }

    #[Groups(['file:item:read', 'file:write'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['file:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;

        return $this;
    }

    public function getPasajero(): ?CotizacionFilepasajero { return $this->pasajero; }
    public function setPasajero(?CotizacionFilepasajero $v): self { $this->pasajero = $v; return $this; }

    public function getGrupo(): ?CotizacionFileGrupo { return $this->grupo; }
    public function setGrupo(?CotizacionFileGrupo $v): self { $this->grupo = $v; return $this; }

    public function isEsJefe(): bool { return $this->esJefe; }
    public function setEsJefe(bool $v): self { $this->esJefe = $v; return $this; }
}
