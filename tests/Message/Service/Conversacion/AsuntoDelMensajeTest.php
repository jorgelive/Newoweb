<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\AsuntoDelMensaje;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * De qué asunto es un mensaje.
 *
 * `setAsunto()` tenía UN llamador —el motor de reglas— y por eso 4889 mensajes de producción
 * nacieron con el par vacío: los entrantes, los del panel y los del agente. `null` significaba
 * a la vez «legado», «no hay asunto» y «sí lo hay pero nadie lo puso», y las tres se leen igual.
 */
final class AsuntoDelMensajeTest extends TestCase
{
    #[Test]
    public function con_un_solo_asunto_se_estampa_sin_preguntar(): void
    {
        // Determinista: da el mismo valor que ya calcula el respaldo. Neutro hoy, correcto en
        // cuanto el hilo tenga dos.
        $mensaje = $this->mensaje($this->hiloCon(['pms_reserva' => 'r-1']));

        $this->servicio()->estampar($mensaje);

        self::assertSame('pms_reserva', $mensaje->getAsuntoType());
        self::assertSame('r-1', $mensaje->getAsuntoId());
    }

    #[Test]
    public function con_dos_asuntos_se_deja_null_porque_es_ambiguo(): void
    {
        // El walk-in que también compró un tour, o el titular con dos reservas. Adivinar aquí
        // mandaría el mensaje a la reserva equivocada; `null` significa «que lo diga el panel».
        $mensaje = $this->mensaje($this->hiloCon(['pms_reserva' => 'r-1', 'cotizacion_file' => 'f-1']));

        $this->servicio()->estampar($mensaje);

        self::assertNull($mensaje->getAsuntoType());
        self::assertNull($mensaje->getAsuntoId());
    }

    #[Test]
    public function sin_asuntos_se_deja_null_y_eso_es_la_verdad(): void
    {
        // Un número desconocido que escribe por WhatsApp: no hay asunto que estampar.
        $mensaje = $this->mensaje($this->hiloCon([]));

        $this->servicio()->estampar($mensaje);

        self::assertNull($mensaje->getAsuntoId());
    }

    #[Test]
    public function el_asunto_elegido_se_respeta_si_cuelga_del_hilo(): void
    {
        $mensaje = $this->mensaje($this->hiloCon(['pms_reserva' => 'r-1', 'cotizacion_file' => 'f-1']));
        $mensaje->setAsunto('cotizacion_file', 'f-1');

        $this->servicio()->estampar($mensaje);

        self::assertSame('cotizacion_file', $mensaje->getAsuntoType());
        self::assertSame('f-1', $mensaje->getAsuntoId());
    }

    #[Test]
    public function un_asunto_que_no_es_de_este_hilo_se_descarta(): void
    {
        // ⚠️ Lo que llega de fuera no se cree. Aceptarlo no sería un texto raro: sería un
        // mensaje aterrizando en la reserva de otra persona. Al borrarlo cae al respaldo de la
        // conversación, que es el comportamiento de siempre.
        $mensaje = $this->mensaje($this->hiloCon(['pms_reserva' => 'r-1']));
        $mensaje->setAsunto('pms_reserva', 'r-DE-OTRO');

        $this->servicio()->estampar($mensaje);

        self::assertNull($mensaje->getAsuntoType());
        self::assertNull($mensaje->getAsuntoId());
    }

    #[Test]
    public function un_par_a_medias_no_cuenta_como_elegido(): void
    {
        // Los setters sueltos existen para el deserializador y permiten dejarlo a medias. Un
        // tipo sin id es un asunto que no existe: se trata como «no lo dijeron».
        $hilo = $this->hiloCon(['pms_reserva' => 'r-1']);
        $mensaje = $this->mensaje($hilo);
        $mensaje->setAsuntoType('pms_reserva');

        $this->servicio()->estampar($mensaje);

        self::assertSame('r-1', $mensaje->getAsuntoId(), 'Se deduce, porque el par venía incompleto.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @var list<ConversacionEnlaceInterface> */
    private array $enlaces = [];

    private function servicio(): AsuntoDelMensaje
    {
        $proveedor = new class ($this->enlaces) implements ProveedorDeEnlacesInterface {
            /** @param list<ConversacionEnlaceInterface> $enlaces */
            public function __construct(private readonly array $enlaces) {}

            public function getNegocio(): string { return 'prueba'; }
            public function paraConversacion(MessageConversation $conversacion): array { return $this->enlaces; }
            public function titularDeAsunto(string $contextType, string $contextId): ?ConversacionEnlaceInterface { return null; }

            public function enlaceDeAsunto(
                MessageConversation $conversacion,
                string $contextType,
                string $contextId
            ): ?ConversacionEnlaceInterface { return null; }
        };

        return new AsuntoDelMensaje(new EnlacesDeConversacion([$proveedor]), new NullLogger());
    }

    /** @param array<string, string> $asuntos contextType => contextId */
    private function hiloCon(array $asuntos): MessageConversation
    {
        $this->enlaces = [];

        foreach ($asuntos as $tipo => $id) {
            $this->enlaces[] = $this->enlace($tipo, $id);
        }

        return new MessageConversation('pms_reserva', 'r-1');
    }

    private function mensaje(MessageConversation $hilo): Message
    {
        $mensaje = new Message();
        $mensaje->setConversation($hilo);

        return $mensaje;
    }

    private function enlace(string $tipo, string $id): ConversacionEnlaceInterface
    {
        return new class ($tipo, $id) implements ConversacionEnlaceInterface {
            public function __construct(private readonly string $tipo, private readonly string $id) {}

            public function getConversacion(): ?MessageConversation { return null; }
            public function getNegocio(): string { return 'prueba'; }
            public function getContextType(): string { return $this->tipo; }
            public function getContextId(): string { return $this->id; }
            public function getVinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }
            public function getMilestones(): array { return []; }
            public function getOrigen(): ?string { return null; }
            public function getAgencia(): ?string { return null; }
            public function procedenciaParaElPrompt(): ?string { return null; }
            public function getCreatedAt(): ?DateTimeImmutable { return null; }
            public function getEtiqueta(): string { return 'Asunto ' . $this->id; }
            public function correoDeContacto(): ?string { return null; }
            public function correoEsExclusivo(): bool { return false; }
            public function esTitular(): bool { return true; }
            public function marcarTitular(bool $esTitular): self { return $this; }
            public function canalesPosibles(): array { return []; }
            public function comoFrente(): Frente { return new Frente('prueba', MomentoDeFrente::Venta, 'Asunto'); }
        };
    }
}
