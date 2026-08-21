<?php

declare(strict_types=1);

namespace App\Exchange\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampTrait;
use App\Exchange\Service\Contract\ChannelConfigInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * El buzón desde el que sale el correo.
 *
 * ── Por qué existe si el transporte ya está en `MAILER_DSN` ─────────────────
 * Porque el motor de Exchange elige el CLIENTE por
 * `ChannelConfigInterface::getProviderName()` del lote, no por el canal del mensaje. Sin una
 * configuración que diga `email`, el lote no encuentra a quién entregarlo.
 *
 * ⚠️ **Las credenciales NO viven aquí.** El DSN de Graph está en `MAILER_DSN` —ver
 * `docs/CorreoSaliente.md`— y ahí se queda: un secreto en una fila de base de datos es un
 * secreto que acaba en un volcado, en una copia de seguridad y en la pantalla de alguien.
 * Esto guarda sólo lo que sí es configuración de negocio: quién firma el correo.
 *
 * ⚠️ **El remitente tiene que ser un BUZÓN, no un alias.** Graph rechaza enviar «como» un alias
 * y el fallo llega tarde, en el envío, no al configurarlo. Está documentado en
 * `docs/CorreoSaliente.md` §6.1, donde costó una tarde.
 */
#[ORM\Entity]
#[ORM\Table(name: 'exchange_email_config')]
#[ORM\HasLifecycleCallbacks]
class EmailConfig implements ChannelConfigInterface
{
    use IdTrait;
    use TimestampTrait;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $nombre = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $activo = true;

    /** El buzón remitente. Un buzón real del tenant, no un alias. */
    #[ORM\Column(type: 'string', length: 180)]
    private string $remitente = '';

    /** Lo que ve el destinatario antes del correo: «OpenPeru» en vez de `reservas@…`. */
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $remitenteNombre = null;

    /** A dónde contestan. Vacío significa «al mismo remitente». */
    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $responderA = null;

    public function __construct()
    {
        $this->initializeId();
    }

    public function getId(): mixed
    {
        return $this->id;
    }

    /** El alias con el que `ExchangeBatchProcessor` localiza `MailerExchangeClient`. */
    public function getProviderName(): string
    {
        return 'email';
    }

    /**
     * No hay URL: el transporte es el mailer de Symfony, configurado por DSN.
     *
     * Se devuelve vacío en vez de inventar una URL de Graph, que sería un dato falso en cuanto
     * el DSN apuntara a otro sitio — y el DSN es el único que sabe a dónde va esto.
     */
    public function getBaseUrl(): string
    {
        return '';
    }

    public function isActivo(): bool { return $this->activo; }
    public function setActivo(bool $activo): self { $this->activo = $activo; return $this; }

    public function getNombre(): ?string { return $this->nombre; }
    public function setNombre(?string $nombre): self { $this->nombre = $nombre; return $this; }

    public function getRemitente(): string { return $this->remitente; }
    public function setRemitente(string $remitente): self { $this->remitente = trim($remitente); return $this; }

    public function getRemitenteNombre(): ?string { return $this->remitenteNombre; }
    public function setRemitenteNombre(?string $v): self { $this->remitenteNombre = $v; return $this; }

    public function getResponderA(): ?string { return $this->responderA; }
    public function setResponderA(?string $v): self { $this->responderA = $v; return $this; }
}
