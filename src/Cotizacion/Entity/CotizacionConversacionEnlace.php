<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Entity\MessageConversation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un EXPEDIENTE de viaje colgado de una conversación.
 *
 * La hermana de {@see \App\Pms\Entity\PmsConversacionEnlace} para el otro negocio. Un cliente de
 * hotel puede además comprar tours: con los dos enlaces, **las dos cosas viven en el mismo
 * hilo** y quien atiende ve el historial completo.
 *
 * ## Por qué el expediente y no la cotización
 *
 * Porque un expediente tiene **varias versiones de cotización** —se propone, el cliente pide
 * cambios, se emite la v2— y el cliente habla de *su viaje*, no de la versión que esté vigente.
 * Colgar la cotización obligaría a mover el enlace en cada revisión, y el hilo contaría una
 * historia partida.
 *
 * ## Su tabla y su clave foránea
 *
 * ⚠️ Tabla propia, como el PMS, y no una común con `context_id` de texto: eso convertiría la FK
 * en una cadena y perdería la integridad. `file_id` **no lleva `CASCADE`** por el mismo motivo
 * que allí — borrar un expediente no puede llevarse por delante el hilo de mensajes con esa
 * persona. Que falle el borrado es preferible a perder la conversación en silencio.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_conversacion_enlace')]
#[ORM\UniqueConstraint(name: 'uq_cot_enlace_conversacion_file', columns: ['conversacion_id', 'file_id'])]
#[ORM\HasLifecycleCallbacks]
class CotizacionConversacionEnlace implements ConversacionEnlaceInterface
{
    use IdTrait;
    use TimestampTrait;

    public const string CONTEXT_TYPE = 'cotizacion_file';

    #[ORM\ManyToOne(targetEntity: MessageConversation::class)]
    #[ORM\JoinColumn(name: 'conversacion_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MessageConversation $conversacion = null;

    /** Ver el docblock de la clase: sin `CASCADE` a propósito. */
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: false)]
    private ?CotizacionFile $file = null;

    /**
     * ¿Vendido o vendiéndose?
     *
     * Lo decide el dominio, y en Travel lo dice el **pago**, no el estado del expediente: un
     * expediente abierto con el prepago hecho es un cliente, y uno abierto sin pagar sigue
     * siendo una venta en curso. Se guarda en vez de derivarse porque el cálculo vive en el
     * módulo financiero y esto lo lee el motor de reglas en caliente.
     */
    #[ORM\Column(type: 'string', length: 30, enumType: VinculoComercial::class, options: ['default' => 'ninguno'])]
    private VinculoComercial $vinculo = VinculoComercial::Ninguno;

    /**
     * Las fechas con las que se programan los envíos.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $milestones = [];

    /** `directo`, `agencia`… De dónde vino ESTE expediente. */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $origen = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $agencia = null;

    public function __construct(MessageConversation $conversacion, CotizacionFile $file)
    {
        $this->initializeId();
        $this->conversacion = $conversacion;
        $this->file = $file;
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getFile(): ?CotizacionFile { return $this->file; }

    public function getConversacion(): ?MessageConversation { return $this->conversacion; }
    public function setConversacion(?MessageConversation $v): self { $this->conversacion = $v; return $this; }

    public function getNegocio(): string { return 'turistico'; }

    public function getContextType(): string { return self::CONTEXT_TYPE; }

    public function getContextId(): string { return (string) $this->file?->getId(); }

    public function getVinculo(): VinculoComercial { return $this->vinculo; }
    public function setVinculo(VinculoComercial $v): self { $this->vinculo = $v; return $this; }

    /**
     * Un expediente vive en VENTA hasta que se opera.
     *
     * Hoy siempre `Venta`: la Biblia sabe cuándo un servicio se está operando, pero el momento
     * del **expediente** —no de cada servicio— todavía no se deriva de ahí. Devolver `Venta` es
     * lo correcto mientras tanto, y es lo que hace que el triaje lo trate como algo que se está
     * vendiendo en vez de como un viaje en curso.
     */
    public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }

    /** @return array<string, string> */
    public function getMilestones(): array { return $this->milestones ?? []; }

    /** @param array<string, string> $milestones */
    public function setMilestones(array $milestones): self { $this->milestones = $milestones; return $this; }

    public function getOrigen(): ?string { return $this->origen; }
    public function setOrigen(?string $v): self { $this->origen = $v; return $this; }

    public function getAgencia(): ?string { return $this->agencia; }
    public function setAgencia(?string $v): self { $this->agencia = $v; return $this; }

    /**
     * De momento nada que añadir al prompt.
     *
     * ⚠️ Devolver `null` es una respuesta legítima del contrato —«no cambia nada»— y es más
     * honesto que inventar una frase. Cuando Travel tenga consecuencias por procedencia —quién
     * gestiona un cambio de fecha, quién emite el comprobante— se escribe aquí, **en positivo
     * para todas las ramas**: una supresión en el prompt ya se ha ignorado varias veces.
     */
    public function procedenciaParaElPrompt(): ?string { return null; }

    /**
     * Lo que se le puede decir a quien escribe: el nombre del grupo y nada más.
     *
     * Ni importes, ni número de expediente: esto es lo único del enlace que puede acabar
     * leyéndole el modelo al cliente.
     */
    public function getEtiqueta(): string
    {
        $nombre = trim((string) ($this->file?->getNombreGrupo() ?? ''));

        return $nombre !== '' ? sprintf('Tu viaje «%s»', $nombre) : 'Tu viaje';
    }

    /**
     * Un expediente de viaje NO tiene conversación en Beds24, y no la va a tener.
     *
     * Beds24 es el channel manager del alojamiento: su hilo de mensajes cuelga de un
     * `bookId`, y un expediente de tours no tiene ninguno. No es que hoy falte el dato —es
     * que no hay dato que falte—, así que el canal se descarta en el origen y no acaba en un
     * mensaje FALLIDO con «posible restricción de negocio por canal», que es lo que veía el
     * operador cuando el corte se hacía tarde, dentro del encolador.
     *
     * `email` está en la lista aunque el canal esté todavía inactivo: la lista dice qué es
     * POSIBLE, y el interruptor `isActive` lo decide el núcleo aparte. Ponerlo al revés
     * obligaría a volver aquí el día que se encienda.
     *
     * @return list<string>
     */
    public function canalesPosibles(): array
    {
        return ['whatsapp_meta', 'email'];
    }

    public function comoFrente(): Frente
    {
        return new Frente(
            negocio: $this->getNegocio(),
            momento: $this->getMomento(),
            etiqueta: $this->getEtiqueta(),
            entidadTipo: self::CONTEXT_TYPE,
            entidadId: $this->getContextId(),
            procedencia: $this->procedenciaParaElPrompt(),
        );
    }
}
