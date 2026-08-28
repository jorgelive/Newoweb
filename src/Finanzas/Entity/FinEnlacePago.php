<?php

declare(strict_types=1);

namespace App\Finanzas\Entity;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Entity\User;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinEnlacePagoRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Enlace de pago enviado al cliente para que cobre por pasarela.
 *
 * Es la unidad de cobro de Finanzas: un importe congelado, una URL con token y el rastro
 * de lo que respondió la pasarela. NO es el pago: el pago sigue viviendo en el módulo de
 * origen (`PmsPagoFinanciero` en el PMS) y lo crea el resolver cuando llega el webhook.
 * Esa separación es deliberada — el saldo de una reserva no puede depender de que Finanzas
 * esté instalado.
 *
 * ## La relación con el origen es SOFT
 *
 * `origenTipo` + `origenId` sustituyen a una foreign key. El porqué está en
 * `FinOrigenCobro`; lo práctico es que **no hay JOIN posible en DQL** contra la reserva:
 * para pintar el nombre del cliente hay que pasar por
 * `FinOrigenCobroRegistry::resolver()`, que despacha al módulo dueño. El índice compuesto
 * `(origen_tipo, origen_id)` es lo que hace barata la consulta "enlaces de esta reserva".
 *
 * ## Los importes están congelados
 *
 * `montoNeto`, `recargoPorcentaje` y `montoTotal` son un SNAPSHOT del momento de crear el
 * enlace, no una lectura viva del saldo. Si el saldo cambia después (se añade un cargo,
 * entra otro pago), el enlace sigue cobrando lo que decía cuando se envió: el cliente ya
 * tiene esa cifra en su correo y cobrarle otra cosa sin avisar sería indefendible. Para
 * cobrar el saldo nuevo se anula el enlace y se emite otro.
 *
 * `montoTotal` = `montoNeto` + recargo. Es lo que se le pasa a la tarjeta. `montoNeto` es
 * lo que abona la deuda — misma semántica que `PmsPagoFinanciero::getMonto()` frente a
 * `getMontoTotalCobrado()`, y por eso el resolver del PMS puede trasladarlos tal cual.
 */
#[ORM\Entity(repositoryClass: FinEnlacePagoRepository::class)]
#[ORM\Table(name: 'fin_enlace_pago')]
// El token es la única credencial de la página pública de pago: sin unicidad garantizada
// por la base de datos, una colisión daría acceso al cobro de otro cliente.
#[ORM\UniqueConstraint(name: 'uniq_fin_enlace_pago_token', columns: ['token'])]
// Sustituye al índice que daría una foreign key: es el que sirve "enlaces de este documento".
#[ORM\Index(name: 'idx_fin_enlace_pago_origen', columns: ['origen_tipo', 'origen_id'])]
#[ORM\Index(name: 'idx_fin_enlace_pago_estado', columns: ['estado'])]
#[ORM\HasLifecycleCallbacks]
class FinEnlacePago
{
    use IdTrait;
    use TimestampTrait;

    /**
     * Secreto público de la URL (`/pago/{token}`), NO el UUID de la fila.
     *
     * Se separa del id a propósito: el UUID v7 lleva marca de tiempo y es enumerable por
     * cercanía, así que un id filtrado en un log delataría vecinos. El token son 32 bytes
     * aleatorios en base64url — imposible de adivinar y revocable emitiendo otro enlace.
     */
    #[ORM\Column(type: 'string', length: 64)]
    #[Groups(['fin_enlace:read'])]
    private string $token = '';

    #[ORM\Column(type: 'string', length: 30, enumType: FinPasarela::class)]
    #[Groups(['fin_enlace:read'])]
    private FinPasarela $pasarela = FinPasarela::IZIPAY;

    /**
     * Módulo al que se imputa el cobro. **Nullable**: un cobro manual puede no pertenecer
     * a ninguno (una venta suelta, un depósito de garantía) y entonces esto queda vacío.
     *
     * Ojo a la distinción con `origenId`, que ahora son dos cosas separables:
     *
     * | `origenTipo` | `origenId` | Qué es |
     * |---|---|---|
     * | pms_reserva  | uuid  | Cobro contra un documento concreto: el resolver lee su saldo |
     * | pms_reserva  | null  | Cobro manual **etiquetado** en ese módulo, sin documento |
     * | null         | null  | Cobro manual suelto |
     *
     * El caso "tipo con id" es el único que imputa dinero en el módulo al cobrarse. Los
     * otros dos sólo dejan el registro en Finanzas.
     */
    #[ORM\Column(name: 'origen_tipo', type: 'string', length: 30, nullable: true, enumType: FinOrigenCobro::class)]
    #[Groups(['fin_enlace:read'])]
    private ?FinOrigenCobro $origenTipo = null;

    /**
     * UUID del documento en su módulo. Sin FK: ver el bloque de la clase.
     *
     * **Nullable**: sin él, el enlace es un cobro manual y no hay nada que imputar en
     * ningún módulo — el dinero se queda registrado sólo en Finanzas.
     */
    #[ORM\Column(name: 'origen_id', type: 'uuid', nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?Uuid $origenId = null;

    #[ORM\Column(type: 'string', length: 20, enumType: FinEnlacePagoEstado::class)]
    #[Groups(['fin_enlace:read'])]
    private FinEnlacePagoEstado $estado = FinEnlacePagoEstado::PENDIENTE;

    #[ORM\ManyToOne(targetEntity: MaestroMoneda::class)]
    #[ORM\JoinColumn(name: 'moneda_id', referencedColumnName: 'id', nullable: false)]
    private ?MaestroMoneda $moneda = null;

    /** Importe que abona la deuda del documento, sin el recargo de la pasarela. */
    #[ORM\Column(name: 'monto_neto', type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['fin_enlace:read'])]
    private string $montoNeto = '0.00';

    /** Recargo en PORCENTAJE (5.50 = 5.5%), como en `PmsPagoFinanciero::$comisionPorcentaje`. */
    #[ORM\Column(name: 'recargo_porcentaje', type: 'decimal', precision: 5, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['fin_enlace:read'])]
    private string $recargoPorcentaje = '0.00';

    /**
     * Lo que se le cobra de verdad a la tarjeta: neto + recargo.
     *
     * Se GUARDA en vez de derivarse de los otros dos porque es la cifra que viajó a la
     * pasarela: si mañana cambia la fórmula del recargo, la conciliación con Izipay tiene
     * que seguir cuadrando contra lo que se cobró aquel día, no contra un recálculo.
     */
    #[ORM\Column(name: 'monto_total', type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['fin_enlace:read'])]
    private string $montoTotal = '0.00';

    /** Texto que ve el cliente en la página de pago. */
    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['fin_enlace:read'])]
    private string $concepto = '';

    /**
     * Referencia legible del documento (localizador) en el momento de emitir.
     *
     * Copiada del origen y no leída al vuelo: es lo que se mandó como `orderId` a la
     * pasarela y lo que aparece en su Backoffice. Tiene que sobrevivir al documento.
     */
    #[ORM\Column(name: 'origen_referencia', type: 'string', length: 60, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $origenReferencia = null;

    // Snapshot del cliente: prellena el formulario de la pasarela y sobrevive al origen.
    #[ORM\Column(name: 'cliente_nombre', type: 'string', length: 150, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $clienteNombre = null;

    #[ORM\Column(name: 'cliente_email', type: 'string', length: 180, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $clienteEmail = null;

    #[ORM\Column(name: 'cliente_telefono', type: 'string', length: 40, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $clienteTelefono = null;

    /** Null = sin caducidad. Ver `estaVigente()`. */
    #[ORM\Column(name: 'expira_en', type: 'datetime_immutable', nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?DateTimeImmutable $expiraEn = null;

    #[ORM\Column(name: 'pagado_en', type: 'datetime_immutable', nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?DateTimeImmutable $pagadoEn = null;

    /**
     * `orderId` enviado a la pasarela. Se genera al crear y NO cambia entre reintentos:
     * es la clave con la que se cruza nuestro enlace y el movimiento en el Backoffice.
     */
    #[ORM\Column(name: 'orden_id', type: 'string', length: 64, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $ordenId = null;

    /** UUID de la transacción en la pasarela, para reclamar o devolver. */
    #[ORM\Column(name: 'transaccion_uuid', type: 'string', length: 64, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $transaccionUuid = null;

    /** Código de autorización del banco. Lo pide el cliente cuando reclama. */
    #[ORM\Column(name: 'autorizacion_codigo', type: 'string', length: 32, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $autorizacionCodigo = null;

    /** Marca/últimos 4 de la tarjeta, tal como los devuelve la pasarela. Nunca el PAN. */
    #[ORM\Column(name: 'medio_detalle', type: 'string', length: 60, nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $medioDetalle = null;

    /**
     * Último `kr-answer` recibido, entero.
     *
     * Se guarda crudo porque cuando una transacción se discute meses después, los campos
     * que hemos mapeado nunca son los que hacen falta. Es el equivalente al payload en
     * bruto de `PmsBeds24WebhookAudit`.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'respuesta_pasarela', type: 'json', nullable: true)]
    private ?array $respuestaPasarela = null;

    /**
     * Id del registro que el módulo de origen creó al cobrarse (el `PmsPagoFinanciero`).
     *
     * También soft, y por el mismo motivo que `origenId`: cada módulo imputa el dinero en
     * su propia tabla y Finanzas no puede tener una FK a todas.
     */
    #[ORM\Column(name: 'movimiento_generado_id', type: 'uuid', nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?Uuid $movimientoGeneradoId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creado_por_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $creadoPor = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['fin_enlace:read'])]
    private ?string $notas = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    // =========================================================================
    // REGLAS DE NEGOCIO
    // =========================================================================

    /**
     * ¿Se puede pagar ahora mismo?
     *
     * Es la única puerta que consulta la página pública. Un estado final (pagado, anulado,
     * expirado) cierra; FALLIDO no, porque reintentar con otra tarjeta es lo normal.
     */
    public function estaVigente(): bool
    {
        if ($this->estado->esFinal()) {
            return false;
        }

        return $this->expiraEn === null || $this->expiraEn > new DateTimeImmutable();
    }

    /**
     * Importe para la pasarela: **céntimos**, entero.
     *
     * Izipay —como toda la plataforma Lyra— cobra en la unidad mínima de la moneda. Mandar
     * `150.00` en vez de `15000` cobra un sol y medio en lugar de ciento cincuenta, y la
     * API lo acepta sin rechistar. Por eso la conversión vive aquí y no en el cliente HTTP.
     */
    public function montoTotalCentimos(): int
    {
        return (int) round((float) $this->montoTotal * 100);
    }

    /**
     * El NETO en céntimos: lo que se devuelve al reembolsar.
     *
     * Se cobra el total y se devuelve el neto, y no es una asimetría por descuido: el recargo
     * fue el coste de pagar con tarjeta —anunciado en el botón antes de pulsarlo— y la pasarela
     * no lo reintegra. Ver `CulqiClient::reembolsar()`.
     */
    public function montoNetoCentimos(): int
    {
        return (int) round((float) $this->montoNeto * 100);
    }

    /** Importe del recargo, derivado. Nunca se guarda: se recalcularía mal si cambia el neto. */
    #[Groups(['fin_enlace:read'])]
    public function getMontoRecargo(): string
    {
        return number_format((float) $this->montoTotal - (float) $this->montoNeto, 2, '.', '');
    }

    // =========================================================================
    // GETTERS Y SETTERS
    // =========================================================================

    #[Groups(['fin_enlace:read'])]
    public function getId(): ?Uuid { return $this->id; }

    public function getToken(): string { return $this->token; }
    public function setToken(string $token): self { $this->token = $token; return $this; }

    public function getPasarela(): FinPasarela { return $this->pasarela; }
    public function setPasarela(FinPasarela $pasarela): self { $this->pasarela = $pasarela; return $this; }

    public function getOrigenTipo(): ?FinOrigenCobro { return $this->origenTipo; }
    public function setOrigenTipo(?FinOrigenCobro $origenTipo): self { $this->origenTipo = $origenTipo; return $this; }

    /**
     * ¿Es un cobro manual? Lo define la AUSENCIA de documento, no el módulo.
     *
     * Un manual etiquetado como PMS sigue siendo manual: nadie le va a imputar el dinero
     * a ninguna reserva, porque no hay reserva a la que imputarlo.
     */
    // Symfony 7 exige que un metodo con #[Groups] empiece por get/is/has/can/set.
    // Se usa `getEsManual` y no `isManual` a proposito: el serializer quita el
    // prefijo `get` y la propiedad sigue llamandose `esManual`, que es la clave
    // que ya consume util/src/types/finEnlacePagoModel.ts. Con `isManual` pasaria
    // a serializarse como `manual` y romperia el frontend.
    #[Groups(['fin_enlace:read'])]
    public function getEsManual(): bool
    {
        return $this->origenId === null;
    }

    /** Etiqueta de módulo para el listado. «Manual» cuando no hay ninguno. */
    #[Groups(['fin_enlace:read'])]
    public function getModuloEtiqueta(): string
    {
        return $this->origenTipo?->modulo() ?? 'Manual';
    }

    public function getOrigenId(): ?Uuid { return $this->origenId; }
    public function setOrigenId(?Uuid $origenId): self { $this->origenId = $origenId; return $this; }

    public function getEstado(): FinEnlacePagoEstado { return $this->estado; }
    public function setEstado(FinEnlacePagoEstado $estado): self { $this->estado = $estado; return $this; }

    #[Groups(['fin_enlace:read'])]
    public function getEstadoEtiqueta(): string { return $this->estado->etiqueta(); }

    public function getMoneda(): ?MaestroMoneda { return $this->moneda; }
    public function setMoneda(?MaestroMoneda $moneda): self { $this->moneda = $moneda; return $this; }

    /** Código ISO ('PEN'); en `MaestroMoneda` el código ES el id, no hay `getCodigo()`. */
    #[Groups(['fin_enlace:read'])]
    public function getMonedaCodigo(): ?string { return $this->moneda?->getId(); }

    #[Groups(['fin_enlace:read'])]
    public function getMonedaSimbolo(): ?string { return $this->moneda?->getSimbolo(); }

    public function getMontoNeto(): string { return $this->montoNeto; }
    public function setMontoNeto(string $montoNeto): self { $this->montoNeto = $montoNeto; return $this; }

    public function getRecargoPorcentaje(): string { return $this->recargoPorcentaje; }
    public function setRecargoPorcentaje(string $recargoPorcentaje): self { $this->recargoPorcentaje = $recargoPorcentaje; return $this; }

    public function getMontoTotal(): string { return $this->montoTotal; }
    public function setMontoTotal(string $montoTotal): self { $this->montoTotal = $montoTotal; return $this; }

    public function getConcepto(): string { return $this->concepto; }
    public function setConcepto(string $concepto): self { $this->concepto = $concepto; return $this; }

    public function getOrigenReferencia(): ?string { return $this->origenReferencia; }
    public function setOrigenReferencia(?string $referencia): self { $this->origenReferencia = $referencia; return $this; }

    public function getClienteNombre(): ?string { return $this->clienteNombre; }
    public function setClienteNombre(?string $nombre): self { $this->clienteNombre = $nombre; return $this; }

    public function getClienteEmail(): ?string { return $this->clienteEmail; }
    public function setClienteEmail(?string $email): self { $this->clienteEmail = $email; return $this; }

    public function getClienteTelefono(): ?string { return $this->clienteTelefono; }
    public function setClienteTelefono(?string $telefono): self { $this->clienteTelefono = $telefono; return $this; }

    public function getExpiraEn(): ?DateTimeImmutable { return $this->expiraEn; }
    public function setExpiraEn(?DateTimeImmutable $expiraEn): self { $this->expiraEn = $expiraEn; return $this; }

    public function getPagadoEn(): ?DateTimeImmutable { return $this->pagadoEn; }
    public function setPagadoEn(?DateTimeImmutable $pagadoEn): self { $this->pagadoEn = $pagadoEn; return $this; }

    public function getOrdenId(): ?string { return $this->ordenId; }
    public function setOrdenId(?string $ordenId): self { $this->ordenId = $ordenId; return $this; }

    public function getTransaccionUuid(): ?string { return $this->transaccionUuid; }
    public function setTransaccionUuid(?string $uuid): self { $this->transaccionUuid = $uuid; return $this; }

    public function getAutorizacionCodigo(): ?string { return $this->autorizacionCodigo; }
    public function setAutorizacionCodigo(?string $codigo): self { $this->autorizacionCodigo = $codigo; return $this; }

    public function getMedioDetalle(): ?string { return $this->medioDetalle; }
    public function setMedioDetalle(?string $detalle): self { $this->medioDetalle = $detalle; return $this; }

    /** @return array<string, mixed>|null */
    public function getRespuestaPasarela(): ?array { return $this->respuestaPasarela; }

    /** @param array<string, mixed>|null $respuesta */
    public function setRespuestaPasarela(?array $respuesta): self { $this->respuestaPasarela = $respuesta; return $this; }

    public function getMovimientoGeneradoId(): ?Uuid { return $this->movimientoGeneradoId; }
    public function setMovimientoGeneradoId(?Uuid $id): self { $this->movimientoGeneradoId = $id; return $this; }

    public function getCreadoPor(): ?User { return $this->creadoPor; }
    public function setCreadoPor(?User $creadoPor): self { $this->creadoPor = $creadoPor; return $this; }

    #[Groups(['fin_enlace:read'])]
    public function getCreadoPorNombre(): ?string { return $this->creadoPor?->getFullname() ?: null; }

    public function getNotas(): ?string { return $this->notas; }
    public function setNotas(?string $notas): self { $this->notas = $notas; return $this; }
}
