<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Entity\Maestro\MaestroPais;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\DocumentoTipoEnum;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un documento que identifica a un pasajero: su DNI, su pasaporte, su carné.
 *
 * ## Por qué es una fila y no dos columnas
 *
 * `CotizacionFilepasajero` tenía **un** `tipodocumento` y **un** `numerodocumento`, sin
 * vencimiento. Un padrón real de grupo desmiente las dos cosas: en el de Punta Cana 2026, 130 de
 * 133 personas llevan **DNI y pasaporte a la vez**, con vencimientos distintos —el DNI de alguien
 * caduca en 2026 y su pasaporte en 2031—, y **100 de los 133 son menores**, así que además
 * necesitan autorización notarial para salir del país.
 *
 * Con columnas, eso son ocho campos y la mitad nulos para los adultos; y el día del carné de
 * extranjería o la visa, una migración.
 *
 * ## Qué NO es
 *
 * ⚠️ No confundir con {@see CotizacionFilearchivo}, que son **adjuntos** del expediente —boletos,
 * facturas, confirmaciones— y se llamaba `CotizacionFiledocumento` precisamente hasta que ese
 * nombre hizo que alguien le metiera dentro un `vencimiento` «para alertar de pasaportes
 * vencidos». Ver `docs/Cotizaciones.md` §6.k.
 *
 * Aquí no hay archivo: **es un dato**. Se consulta por vencimiento, no se descarga. El escaneo, si
 * algún día hace falta, es un archivo aparte y se queda del lado del operador.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_pasajero_identificacion')]
#[ORM\UniqueConstraint(name: 'uniq_pasajero_identificacion_tipo', columns: ['pasajero_id', 'tipo'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionPasajeroIdentificacion
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: CotizacionFilepasajero::class, inversedBy: 'identificaciones')]
    #[ORM\JoinColumn(name: 'pasajero_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?CotizacionFilepasajero $pasajero = null;

    /**
     * ⚠️ Único por `(pasajero, tipo)`: nadie tiene dos pasaportes vigentes en el mismo expediente,
     * y esa restricción es la que hace que **reimportar el padrón corregido no duplique nada**.
     * Ese Excel se vuelve a subir varias veces antes de un viaje.
     */
    #[Assert\NotNull(message: 'Indica qué documento es.')]
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 20, enumType: DocumentoTipoEnum::class)]
    private ?DocumentoTipoEnum $tipo = null;

    #[Assert\NotBlank(message: 'El número del documento no puede quedar vacío.')]
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $numero = null;

    /**
     * Nulo significa «no lo sabemos», no «no caduca».
     *
     * ⚠️ La diferencia importa: en el padrón real había **22 personas sin esta fecha**, y sin ella
     * no se puede comprobar nada. Un listado que las cuente como vigentes miente; tienen que salir
     * como «sin comprobar», que es lo que son.
     */
    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    #[ORM\Column(type: 'date', nullable: true)]
    private ?DateTimeInterface $vencimiento = null;

    /** Quién lo emitió. Nulo se lee como «el país del pasajero». */
    #[Groups(['file:item:read', 'file:write'])]
    #[ORM\ManyToOne(targetEntity: MaestroPais::class)]
    #[ORM\JoinColumn(name: 'pais_emisor_id', referencedColumnName: 'id', nullable: true)]
    private ?MaestroPais $paisEmisor = null;

    public function __construct()
    {
        $this->initializeId();
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->tipo->value ?? 'DOC', $this->numero ?? '—');
    }

    #[Groups(['file:item:read', 'file:write', 'pax_file:read'])]
    public function getId(): ?Uuid { return $this->id; }

    #[Groups(['file:write'])]
    public function setId(Uuid|string $id): self
    {
        $this->id = is_string($id) ? Uuid::fromString($id) : $id;

        return $this;
    }

    public function getPasajero(): ?CotizacionFilepasajero { return $this->pasajero; }
    public function setPasajero(?CotizacionFilepasajero $v): self { $this->pasajero = $v; return $this; }

    public function getTipo(): ?DocumentoTipoEnum { return $this->tipo; }
    public function setTipo(?DocumentoTipoEnum $v): self { $this->tipo = $v; return $this; }

    public function getNumero(): ?string { return $this->numero; }

    public function setNumero(?string $v): self
    {
        // Sin espacios ni guiones sueltos: un número copiado de un Excel trae de todo, y dos
        // formas del mismo número son dos personas distintas para cualquier cruce posterior.
        $this->numero = $v !== null ? (trim($v) ?: null) : null;

        return $this;
    }

    public function getVencimiento(): ?DateTimeInterface { return $this->vencimiento; }
    public function setVencimiento(?DateTimeInterface $v): self { $this->vencimiento = $v; return $this; }

    public function getPaisEmisor(): ?MaestroPais { return $this->paisEmisor; }
    public function setPaisEmisor(?MaestroPais $v): self { $this->paisEmisor = $v; return $this; }

    /**
     * ¿Sirve para viajar en esta fecha?
     *
     * `null` es **«no se sabe»** y no `true`: sin fecha cargada no se puede afirmar que esté
     * vigente, y contarlo como bueno es exactamente lo que dejó a once personas con el DNI
     * vencido dentro de un padrón que se daba por revisado.
     */
    public function estaVigenteEl(DateTimeInterface $fecha): ?bool
    {
        if ($this->vencimiento === null) {
            return null;
        }

        return $this->vencimiento->getTimestamp() >= $fecha->getTimestamp();
    }
}
