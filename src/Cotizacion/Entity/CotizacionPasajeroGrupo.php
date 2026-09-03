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
 * ## Por qué sigue siendo entidad y no un `ManyToMany`
 *
 * ⚠️ Nació con un `esJefe`, y el padrón real lo desmintió: hay **9 coordinadores para 9 grupos**,
 * uno cada uno, así que quién lidera ya lo dice {@see PasajeroTipoEnum} y la bandera no cambiaba
 * nada — pero sí podía abrir una puerta de acceso. Se quitó.
 *
 * Se queda como entidad de todas formas: lleva su propio `id` y sus `timestamps`, hace falta para
 * la unicidad `(pasajero, grupo)` con nombre propio, y es donde vive
 * {@see self::validarMismoExpediente()}. Convertirla en `ManyToMany` ahora sólo cambiaría el
 * mecanismo por otro sin ganar nada, y el día que la pertenencia necesite un dato —fecha de alta,
 * quién la asignó— habría que deshacerlo.
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
    /**
     * El código de ESTA persona dentro de ESTE subgrupo. Su localizador de vuelo, su número de
     * habitación, su asiento.
     *
     * ⚠️ **Va en la pertenencia y no en el pasajero**, porque no es suyo: es suyo *en ese grupo*.
     * La misma persona lleva un localizador en la reserva aérea de ida y otro en la de vuelta, y
     * un número de habitación distinto en cada hotel. Colgarlo del pasajero obligaría a inventar
     * un campo por eje y a elegir cuál gana.
     *
     * ⚠️ Es **el dato que hace que la operativa tenga que pedir identidad**: el itinerario es el
     * mismo para todos, pero esto no. Ver `docs/Cotizaciones.md` §6.j.5.
     */
    #[Groups(['cotizacion:read', 'cotizacion:write', 'file:item:read'])]
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $codigo = null;

    public function getCodigo(): ?string { return $this->codigo; }

    public function setCodigo(?string $codigo): self { $this->codigo = $codigo; return $this; }

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

}
