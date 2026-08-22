<?php

declare(strict_types=1);

namespace App\Travel\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Travel\Enum\PuntoTipoEnum;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un sitio concreto donde se recoge o se deja a alguien.
 *
 * Es lo que faltaba para poder decirle a un proveedor **dónde recoge y dónde deja**, que es la
 * primera pregunta que hace al recibir una orden de servicio y hasta ahora se respondía a mano.
 *
 * ## Por qué no vale `TravelLugar`
 *
 * `TravelLugar` es vocabulario de COBERTURA —«Cusco», «Valle Sagrado»— y es deliberadamente
 * ambiguo y multivaluado: sirve para filtrar el cuadro de tráfico, no para que un conductor
 * sepa a qué puerta ir. Un punto es lo contrario: **uno solo, concreto y con dirección**.
 * Los dos conviven y el punto se etiqueta con un lugar, que es lo que permite agrupar el
 * desplegable sin duplicar el vocabulario.
 *
 * ## Por qué no basta con el proveedor
 *
 * Porque casi ningún punto es un proveedor: una estación de tren, la Plaza de Armas o un
 * aeropuerto no facturan nada. `$organizacion` existe para el caso en que **sí** lo sea —un
 * hotel del catálogo— y entonces la dirección se hereda de su ficha en vez de reescribirse:
 * dos direcciones para el mismo hotel acaban divergiendo, y la que se manda al proveedor sería
 * la que no se actualizó.
 *
 * ## Dónde se usa
 *
 * En {@see TravelSegmento::$inicioPunto} / {@see TravelSegmento::$finPunto}, y sólo cuando el
 * modo correspondiente es {@see \App\Travel\Enum\PuntoModoEnum::FIJO}. Cuando el extremo es el
 * hotel del pasajero no hay punto que guardar: se resuelve al emitir la orden.
 */
#[ORM\Entity]
#[ORM\Table(name: 'travel_punto')]
#[ORM\UniqueConstraint(name: 'uniq_travel_punto_nombre', columns: ['nombre'])]
#[ORM\HasLifecycleCallbacks]
class TravelPunto
{
    use IdTrait;
    use TimestampTrait;

    /**
     * El `unique` es la misma defensa que en `TravelLugar`: sin él acaban conviviendo
     * «Estación Ollantaytambo» y «Estacion de Ollantaytambo» como dos sitios distintos, y el
     * desplegable deja de ser fiable justo cuando más filas tiene.
     */
    #[Assert\NotBlank(message: 'El nombre del punto es obligatorio.')]
    #[ORM\Column(type: 'string', length: 120, unique: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'string', length: 30, enumType: PuntoTipoEnum::class, options: ['default' => 'otro'])]
    private PuntoTipoEnum $tipo = PuntoTipoEnum::OTRO;

    /** En qué centro de operación cae. Sirve para agrupar y filtrar, no para decidir nada. */
    #[ORM\ManyToOne(targetEntity: TravelLugar::class)]
    #[ORM\JoinColumn(name: 'lugar_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TravelLugar $lugar = null;

    /**
     * Lo que se le manda al proveedor. Puede quedar vacío si el punto es un hotel del catálogo:
     * en ese caso manda la dirección de su ficha — ver {@see self::direccionEfectiva()}.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $direccion = null;

    /**
     * La coletilla que evita la llamada de teléfono: «puerta lateral», «frente al banco», «el
     * bus no puede entrar, se camina 50 m». Es texto libre a propósito.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referencia = null;

    /**
     * Cuando el punto ES un proveedor del catálogo (un hotel).
     *
     * `SET NULL` y no `CASCADE`: si se retira el proveedor, el punto sigue existiendo —la gente
     * se sigue recogiendo ahí— y lo único que se pierde es el vínculo. Borrar el punto dejaría
     * mudos todos los segmentos que lo usaban, y eso no se ve hasta que sale una orden sin
     * dirección.
     */
    #[ORM\ManyToOne(targetEntity: TravelOrganizacion::class)]
    #[ORM\JoinColumn(name: 'organizacion_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TravelOrganizacion $organizacion = null;

    /** Retirar del desplegable sin romper los segmentos que ya lo referencian. */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        return $this->nombre ?? 'Sin nombre';
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $v): self { $this->nombre = $v; return $this; }

    public function getTipo(): PuntoTipoEnum { return $this->tipo; }
    public function setTipo(PuntoTipoEnum $v): self { $this->tipo = $v; return $this; }

    public function getLugar(): ?TravelLugar { return $this->lugar; }
    public function setLugar(?TravelLugar $v): self { $this->lugar = $v; return $this; }

    public function getDireccion(): ?string { return $this->direccion; }
    public function setDireccion(?string $v): self { $this->direccion = $v; return $this; }

    public function getReferencia(): ?string { return $this->referencia; }
    public function setReferencia(?string $v): self { $this->referencia = $v; return $this; }

    public function getOrganizacion(): ?TravelOrganizacion { return $this->organizacion; }
    public function setOrganizacion(?TravelOrganizacion $v): self { $this->organizacion = $v; return $this; }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $v): self { $this->activo = $v; return $this; }

    /**
     * La dirección que va en la orden: la propia si la hay, y si no la de su proveedor.
     *
     * En ese orden y no al revés: la propia se escribe cuando la ficha del proveedor no sirve
     * —la puerta de servicio, otra sede—, así que si alguien se molestó en escribirla, manda.
     */
    public function direccionEfectiva(): ?string
    {
        $propia = trim((string) $this->direccion);

        if ($propia !== '') {
            return $propia;
        }

        $delProveedor = trim((string) $this->organizacion?->getDireccion());

        return $delProveedor !== '' ? $delProveedor : null;
    }

    /**
     * Cómo se nombra el punto en la orden que lee el proveedor.
     *
     * Nombre y dirección van juntos porque por separado no sirven: «Estación de Ollantaytambo»
     * sin dirección obliga a buscarla, y una dirección sin nombre no se reconoce. La referencia
     * va entre paréntesis al final porque es lo que se lee al llegar, no al planificar.
     */
    public function paraLaOrden(): string
    {
        $partes = array_filter([
            $this->nombre,
            $this->direccionEfectiva(),
        ], static fn (?string $p): bool => trim((string) $p) !== '');

        $texto = implode(' — ', $partes);
        $referencia = trim((string) $this->referencia);

        return $referencia !== '' ? $texto . ' (' . $referencia . ')' : $texto;
    }

    /** ¿Se puede mandar a un proveedor, o le faltaría la dirección? */
    public function estaCompleto(): bool
    {
        return trim((string) $this->nombre) !== '' && $this->direccionEfectiva() !== null;
    }
}
