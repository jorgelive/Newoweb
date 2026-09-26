<?php

declare(strict_types=1);

namespace App\Cotizacion\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\User;
use App\Cotizacion\ApiPlatform\State\CotizacionPedidoProcessor;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Algo que un cliente pidió de viaje —un tour, una cotización, un cambio de itinerario— y que el
 * área de Cotizaciones tiene que trabajar.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * La hermana de {@see \App\Pms\Entity\PmsPeticion}, para el otro negocio. El caso que lo destapó:
 * un huésped de hotel pidió por WhatsApp tres tours (Valle Sagrado, Montaña de 7 Colores, Glaciar
 * Quelccaya) con fechas y personas. El agente no tenía cómo resolverlo solo —hay que armar
 * itinerario, precio y disponibilidad—, así que alguien tuvo que intervenir a mano, y el pedido
 * sólo quedó escrito en el chat: nada lo marcaba como «esto hay que trabajarlo».
 *
 * `escalar_al_equipo` no bastaba, por el mismo motivo que no bastaba para las peticiones de
 * limpieza: manda un WhatsApp que se lee una vez, no una tarea que espere hasta que alguien de
 * Cotizaciones tenga un hueco.
 *
 * ── Cuelga de la CONVERSACIÓN, no del expediente ────────────────────────────
 * Al pedirlo, el expediente casi nunca existe todavía —es lo que hay que crear—. Por eso el
 * pedido nace atado a la conversación (igual que `PmsPeticion` guarda su `conversacionId`: otro
 * módulo, mismo desacople deliberado) y `file` se rellena **cuando alguien empieza a trabajarlo**.
 *
 * ── Cómo se cierra ──────────────────────────────────────────────────────────
 * Solo, en cuanto se vincula un expediente a esa conversación: es la señal de que alguien ya lo
 * está trabajando, y no hace falta pedirle a nadie que marque nada. Ver
 * `CotizacionSincronizadorDeEnlace::sincronizar()`. También se puede cerrar a mano desde el panel
 * —un pedido duplicado, o uno que el cliente retiró— igual que `PmsPeticion`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cotizacion_pedido')]
#[ORM\Index(columns: ['conversacion_id', 'efectuada_at'], name: 'idx_pedido_conversacion')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CotizacionPedido',
    operations: [
        // Los pendientes de trabajar. El área de Cotizaciones no ve el resto del PMS.
        new GetCollection(
            uriTemplate: '/pedidos',
            security: "is_granted('" . Roles::RESERVAS_SHOW . "') or is_granted('" . Roles::CUSTOMER_SUPPORT . "')"
        ),
        // Marcarlo a mano. El texto lo escribe quien lo pidió; reescribirlo cambiaría lo que dijo
        // el cliente.
        new Patch(
            uriTemplate: '/pedidos/{id}',
            security: "is_granted('" . Roles::RESERVAS_WRITE . "') or is_granted('" . Roles::CUSTOMER_SUPPORT . "')",
            denormalizationContext: ['groups' => ['cotizacion_pedido:write']],
            // Quién lo marcó lo pone el servidor, no el navegador: ver el procesador.
            processor: CotizacionPedidoProcessor::class
        ),
    ],
    routePrefix: '/cotizacion',
    normalizationContext: ['groups' => ['cotizacion_pedido:read']],
)]
// Por conversación, que es como se pregunta al abrir un hilo: «¿qué pedido dejó pendiente esta
// persona?». No hay `ExistsFilter` de `efectuadaAt` como en `PmsPeticion` porque aquí la lista que
// importa es una sola: los pendientes de todo el negocio, sin acotar por conversación.
#[ApiFilter(SearchFilter::class, properties: ['conversacionId' => 'exact'])]
class CotizacionPedido
{
    use IdTrait;
    use TimestampTrait;

    public function __construct(string $conversacionId, string $texto)
    {
        $this->initializeId();
        $this->conversacionId = $conversacionId;
        $this->texto = $texto;
    }

    /**
     * De qué conversación salió. Ver el docblock de la clase: aquí nace, no en el expediente.
     */
    #[ORM\Column(name: 'conversacion_id', type: 'string', length: 36)]
    #[Groups(['cotizacion_pedido:read'])]
    private string $conversacionId;

    /**
     * Lo que pidió, en una línea y con sus palabras.
     *
     * Corto a propósito, igual que `PmsPeticion::$texto`: es una lista para mirar de un vistazo,
     * no el historial del chat.
     */
    #[ORM\Column(type: 'string', length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[Groups(['cotizacion_pedido:read'])]
    private string $texto = '';

    /** Quién lo anotó. `null` = lo anotó el agente, que es el caso normal. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $creadaPor = null;

    /**
     * El expediente que lo resolvió. `null` mientras está pendiente.
     *
     * Sin `CASCADE`, como en `CotizacionConversacionEnlace`: si el expediente se borrara —no
     * debería, «no se borra, se marca»— este registro no tiene por qué irse con él.
     */
    #[ORM\ManyToOne(targetEntity: CotizacionFile::class)]
    #[ORM\JoinColumn(name: 'file_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['cotizacion_pedido:read'])]
    private ?CotizacionFile $file = null;

    /** Cuándo se resolvió. `null` = pendiente. */
    #[ORM\Column(name: 'efectuada_at', type: 'datetime_immutable', nullable: true)]
    #[Groups(['cotizacion_pedido:read', 'cotizacion_pedido:write'])]
    private ?DateTimeImmutable $efectuadaAt = null;

    /**
     * Quién lo dio por hecho. `null` en los dos casos que no son «una persona lo marcó a mano»:
     * pendiente todavía, o cerrado solo al vincular el expediente ({@see self::getFile()} no nulo
     * en ese caso — es lo que distingue uno de otro).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'efectuada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $efectuadaPor = null;

    public function getConversacionId(): string { return $this->conversacionId; }

    public function getTexto(): string { return $this->texto; }
    public function setTexto(string $texto): self { $this->texto = $texto; return $this; }

    public function getCreadaPor(): ?User { return $this->creadaPor; }
    public function setCreadaPor(?User $creadaPor): self { $this->creadaPor = $creadaPor; return $this; }

    public function getFile(): ?CotizacionFile { return $this->file; }
    public function setFile(?CotizacionFile $file): self { $this->file = $file; return $this; }

    public function getEfectuadaAt(): ?DateTimeImmutable { return $this->efectuadaAt; }
    public function setEfectuadaAt(?DateTimeImmutable $efectuadaAt): self { $this->efectuadaAt = $efectuadaAt; return $this; }

    public function getEfectuadaPor(): ?User { return $this->efectuadaPor; }
    public function setEfectuadaPor(?User $efectuadaPor): self { $this->efectuadaPor = $efectuadaPor; return $this; }

    #[Groups(['cotizacion_pedido:read'])]
    public function isPendiente(): bool { return $this->efectuadaAt === null; }

    /** Se cerró solo, al vincular un expediente a la conversación — nadie lo marcó a mano. */
    #[Groups(['cotizacion_pedido:read'])]
    public function isCerradoAutomaticamente(): bool
    {
        return $this->efectuadaAt !== null && $this->efectuadaPor === null;
    }

    /** Quién lo dio por hecho, para la lista. El objeto entero no hace falta ahí. */
    #[Groups(['cotizacion_pedido:read'])]
    public function getEfectuadaPorNombre(): ?string
    {
        return $this->efectuadaPor?->getUserIdentifier();
    }

    /** El localizador del expediente que lo resolvió, si ya hay uno. */
    #[Groups(['cotizacion_pedido:read'])]
    public function getFileLocalizador(): ?string
    {
        return $this->file?->getLocalizadorPublico();
    }

    /**
     * Marca el pedido como resuelto por vincularse un expediente a su conversación.
     *
     * No es un setter cualquiera: pone las tres cosas a la vez (expediente, hora, sin autor
     * humano) para que no se pueda dejar a medias — un `file` puesto sin `efectuadaAt` seguiría
     * apareciendo como pendiente en la lista, y un `efectuadaAt` sin `file` no diría qué lo
     * resolvió.
     */
    public function resolverPorExpediente(CotizacionFile $file): self
    {
        $this->file = $file;
        $this->efectuadaAt = new DateTimeImmutable();
        $this->efectuadaPor = null;

        return $this;
    }

    public function __toString(): string
    {
        return $this->texto;
    }
}
