<?php

declare(strict_types=1);

namespace App\Finanzas\Entity;

use App\Entity\Trait\IdTrait;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinPasarelaCobroAuditRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Traza de cada INTENTO de cobro contra una pasarela. Una fila por llamada, no por pago.
 *
 * Hermana de {@see FinPasarelaWebhookAudit}, que guarda lo que la pasarela nos manda a
 * nosotros; ésta guarda lo que le pedimos nosotros a ella. Faltaba la mitad de la
 * conversación, y es la mitad donde está el dinero.
 *
 * ## Por qué existe: hay implementaciones que sólo se pueden terminar mirando datos reales
 *
 * El reto 3-D Secure **no se puede provocar desde aquí**: lo pide el banco emisor de una
 * tarjeta extranjera, y no tenemos ninguna. Entre el 26/08 y el 05/09/2026 hubo cinco
 * intentos reales de España y Australia, se perdieron los cinco, y lo único que quedó fue una
 * línea de log repetida cinco veces sin el cuerpo de la respuesta. Reconstruir qué había
 * pasado costó leer el bundle de la librería y contar líneas del `error.log`.
 *
 * Con esta tabla, el primer pago extranjero **cierra la implementación por sí solo**: la
 * secuencia de filas de un mismo enlace se lee de un vistazo —
 *
 * ```
 * 12:03:41  con3ds=0  reto_3ds    action_code=REVIEW
 * 12:04:58  con3ds=1  pagado      outcome=venta_exitosa  chr_live_…
 * ```
 *
 * — y contesta las tres preguntas que hoy están abiertas: si el reto llega, si el token se
 * puede reutilizar y qué `outcome.type` trae un cargo que pasó por el banco.
 *
 * ## Se abre ANTES de llamar a la pasarela
 *
 * A propósito, y por la misma razón que el audit de webhooks se persiste antes de validar la
 * firma: la fila que más interesa es la del intento que **no volvió**. Si la red se corta
 * entre el cargo y nuestra respuesta, el dinero pudo salir y aquí queda la única prueba de
 * que se pidió; un intento que se queda en `iniciado` es exactamente eso.
 *
 * ⚠️ **Nunca lleva datos del titular.** El cuerpo se guarda sin `source`, `antifraud_details`
 * ni `client` —tarjeta, correo, nombre, huella del dispositivo—, que no hacen falta para
 * entender qué contestó la pasarela y sí sobran en una tabla que se consulta meses después.
 */
#[ORM\Entity(repositoryClass: FinPasarelaCobroAuditRepository::class)]
#[ORM\Table(name: 'fin_pasarela_cobro_audit')]
#[ORM\Index(name: 'idx_fin_cobro_audit_intentado', columns: ['intentado_en'])]
#[ORM\Index(name: 'idx_fin_cobro_audit_enlace', columns: ['enlace_id'])]
class FinPasarelaCobroAudit
{
    use IdTrait;

    /** Se pidió el cargo y todavía no sabemos nada. Si se queda así, la llamada no volvió. */
    public const DESENLACE_INICIADO = 'iniciado';
    /** Cargo autorizado y enlace saldado. */
    public const DESENLACE_PAGADO = 'pagado';
    /** La pasarela pide autenticar al titular: no es un no, es un paso más. */
    public const DESENLACE_RETO_3DS = 'reto_3ds';
    /** El banco dijo que no. El enlace queda FALLIDO, que no es final. */
    public const DESENLACE_RECHAZADO = 'rechazado';
    /** Vino algo con forma de cargo que NO salda el enlace: importe, moneda u `outcome`. */
    public const DESENLACE_NO_SALDA = 'no_salda';
    /** No se pudo ni hablar con la pasarela: red, tiempo agotado, llaves. */
    public const DESENLACE_ERROR = 'error';

    #[ORM\Column(type: 'string', length: 30, enumType: FinPasarela::class)]
    private FinPasarela $pasarela = FinPasarela::CULQI;

    #[ORM\Column(name: 'intentado_en', type: 'datetime_immutable')]
    private DateTimeImmutable $intentadoEn;

    /** Enlace que se estaba cobrando. Soft, como todo en este módulo. */
    #[ORM\Column(name: 'enlace_id', type: 'uuid', nullable: true)]
    private ?Uuid $enlaceId = null;

    /**
     * Si el intento llevaba los parámetros del reto ya resuelto.
     *
     * Es lo que distingue el primer intento del reintento, y por tanto lo que permite leer
     * una secuencia de 3DS sin adivinar.
     */
    #[ORM\Column(name: 'con_3ds', type: 'boolean')]
    private bool $con3DS = false;

    #[ORM\Column(type: 'string', length: 20)]
    private string $desenlace = self::DESENLACE_INICIADO;

    /** El `object` que devolvió la pasarela: `charge`, `error`, o lo que traiga un reto. */
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $objeto = null;

    /** `action_code` de Culqi. `REVIEW` es como pide el reto; el demo oficial mira esto. */
    #[ORM\Column(name: 'action_code', type: 'string', length: 40, nullable: true)]
    private ?string $actionCode = null;

    /** `outcome.type`: el veredicto de verdad (`venta_exitosa`, `operacion_denegada`…). */
    #[ORM\Column(name: 'outcome_type', type: 'string', length: 40, nullable: true)]
    private ?string $outcomeType = null;

    /** `outcome.code`: `AUT0000` autoriza, `DNGE0116` pide 3DS. */
    #[ORM\Column(name: 'outcome_code', type: 'string', length: 40, nullable: true)]
    private ?string $outcomeCode = null;

    /** Id del cargo en la pasarela, si llegó a existir. */
    #[ORM\Column(name: 'cargo_id', type: 'string', length: 60, nullable: true)]
    private ?string $cargoId = null;

    /** Lo que la pasarela le dice al comercio, o el error nuestro. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motivo = null;

    /**
     * El cuerpo entero, sin los datos del titular.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $respuesta = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->intentadoEn = new DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getPasarela(): FinPasarela { return $this->pasarela; }
    public function setPasarela(FinPasarela $pasarela): self { $this->pasarela = $pasarela; return $this; }

    public function getIntentadoEn(): DateTimeImmutable { return $this->intentadoEn; }

    public function getEnlaceId(): ?Uuid { return $this->enlaceId; }
    public function setEnlaceId(?Uuid $enlaceId): self { $this->enlaceId = $enlaceId; return $this; }

    public function isCon3DS(): bool { return $this->con3DS; }
    public function setCon3DS(bool $con3DS): self { $this->con3DS = $con3DS; return $this; }

    public function getDesenlace(): string { return $this->desenlace; }
    public function setDesenlace(string $desenlace): self { $this->desenlace = $desenlace; return $this; }

    public function getObjeto(): ?string { return $this->objeto; }
    public function setObjeto(?string $objeto): self { $this->objeto = $objeto; return $this; }

    public function getActionCode(): ?string { return $this->actionCode; }
    public function setActionCode(?string $actionCode): self { $this->actionCode = $actionCode; return $this; }

    public function getOutcomeType(): ?string { return $this->outcomeType; }
    public function setOutcomeType(?string $tipo): self { $this->outcomeType = $tipo; return $this; }

    public function getOutcomeCode(): ?string { return $this->outcomeCode; }
    public function setOutcomeCode(?string $codigo): self { $this->outcomeCode = $codigo; return $this; }

    public function getCargoId(): ?string { return $this->cargoId; }
    public function setCargoId(?string $cargoId): self { $this->cargoId = $cargoId; return $this; }

    public function getMotivo(): ?string { return $this->motivo; }
    public function setMotivo(?string $motivo): self { $this->motivo = $motivo; return $this; }

    /** @return array<string, mixed>|null */
    public function getRespuesta(): ?array { return $this->respuesta; }

    /** @param array<string, mixed>|null $respuesta */
    public function setRespuesta(?array $respuesta): self { $this->respuesta = $respuesta; return $this; }
}
