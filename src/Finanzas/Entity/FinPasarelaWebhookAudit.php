<?php

declare(strict_types=1);

namespace App\Finanzas\Entity;

use App\Entity\Trait\IdTrait;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Repository\FinPasarelaWebhookAuditRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Traza en crudo de cada notificación (IPN) recibida de una pasarela.
 *
 * Mismo papel que `PmsBeds24WebhookAudit` con Beds24, y por la misma razón aprendida: si
 * un cobro no aparece, la pregunta que hay que poder responder es "¿nos llegó el aviso?".
 * Sin esta tabla la respuesta es un encogimiento de hombros, porque la pasarela no
 * reintenta indefinidamente ni guarda el cuerpo exacto que mandó.
 *
 * Se persiste ANTES de validar la firma, a propósito: los webhooks rechazados por firma
 * inválida son justo los que más interesa poder mirar.
 */
#[ORM\Entity(repositoryClass: FinPasarelaWebhookAuditRepository::class)]
#[ORM\Table(name: 'fin_pasarela_webhook_audit')]
#[ORM\Index(name: 'idx_fin_webhook_audit_recibido', columns: ['recibido_en'])]
class FinPasarelaWebhookAudit
{
    use IdTrait;

    public const ESTADO_RECIBIDO = 'recibido';
    public const ESTADO_PROCESADO = 'procesado';
    public const ESTADO_IGNORADO = 'ignorado';
    public const ESTADO_ERROR = 'error';

    #[ORM\Column(type: 'string', length: 30, enumType: FinPasarela::class)]
    private FinPasarela $pasarela = FinPasarela::IZIPAY;

    #[ORM\Column(name: 'recibido_en', type: 'datetime_immutable')]
    private DateTimeImmutable $recibidoEn;

    #[ORM\Column(name: 'remote_ip', type: 'string', length: 45, nullable: true)]
    private ?string $remoteIp = null;

    /** Cuerpo tal cual llegó, sin decodificar: es la única prueba de qué se firmó. */
    #[ORM\Column(name: 'payload_raw', type: 'text', nullable: true)]
    private ?string $payloadRaw = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $estado = self::ESTADO_RECIBIDO;

    /** Enlace al que se imputó, si se pudo resolver. Soft, como todo en este módulo. */
    #[ORM\Column(name: 'enlace_id', type: 'uuid', nullable: true)]
    private ?Uuid $enlaceId = null;

    #[ORM\Column(name: 'error_mensaje', type: 'text', nullable: true)]
    private ?string $errorMensaje = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->recibidoEn = new DateTimeImmutable();
    }

    public function getId(): ?Uuid { return $this->id; }

    public function getPasarela(): FinPasarela { return $this->pasarela; }
    public function setPasarela(FinPasarela $pasarela): self { $this->pasarela = $pasarela; return $this; }

    public function getRecibidoEn(): DateTimeImmutable { return $this->recibidoEn; }

    public function getRemoteIp(): ?string { return $this->remoteIp; }
    public function setRemoteIp(?string $ip): self { $this->remoteIp = $ip; return $this; }

    public function getPayloadRaw(): ?string { return $this->payloadRaw; }
    public function setPayloadRaw(?string $raw): self { $this->payloadRaw = $raw; return $this; }

    public function getEstado(): string { return $this->estado; }
    public function setEstado(string $estado): self { $this->estado = $estado; return $this; }

    public function getEnlaceId(): ?Uuid { return $this->enlaceId; }
    public function setEnlaceId(?Uuid $enlaceId): self { $this->enlaceId = $enlaceId; return $this; }

    public function getErrorMensaje(): ?string { return $this->errorMensaje; }
    public function setErrorMensaje(?string $mensaje): self { $this->errorMensaje = $mensaje; return $this; }
}
