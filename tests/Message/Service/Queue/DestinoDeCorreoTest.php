<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Queue;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Queue\EmailSendEnqueuer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A qué correo se le escribe.
 *
 * ── Por qué no basta «el correo de la persona» ──────────────────────────────
 * Booking emite **un alias por reserva**: `sacuna.311134@guest.booking.com` y
 * `sacuna.672272@guest.booking.com` son la misma señora y dos estancias distintas. En un hilo
 * con las dos, «el correo de esa persona» no existe — y elegir por orden de llegada es
 * escribirle al hilo equivocado de la plataforma.
 *
 * Medido: los **25** correos-identidad de la base son alias de Booking.
 */
final class DestinoDeCorreoTest extends TestCase
{
    #[Test]
    public function con_el_asunto_elegido_manda_su_correo(): void
    {
        // Es el caso que lo motivó: dos estancias de Booking, dos alias, ninguno principal.
        $hilo = $this->hilo();
        $enq = $this->encolador($hilo, [
            ['r-1', 'sacuna.311134@guest.booking.com'],
            ['r-2', 'sacuna.672272@guest.booking.com'],
        ]);

        self::assertSame('sacuna.672272@guest.booking.com', $this->destino($enq, $hilo, 'pms_reserva', 'r-2'));
        self::assertSame('sacuna.311134@guest.booking.com', $this->destino($enq, $hilo, 'pms_reserva', 'r-1'));
    }

    #[Test]
    public function sin_asunto_y_con_dos_alias_no_se_elige_ninguno(): void
    {
        // Sin decir de qué estancia se habla no hay respuesta correcta, y mandarlo al alias
        // equivocado saca la conversación del hilo bueno de Booking.
        $hilo = $this->hilo();
        $enq = $this->encolador($hilo, [
            ['r-1', 'sacuna.311134@guest.booking.com'],
            ['r-2', 'sacuna.672272@guest.booking.com'],
        ]);

        self::assertNull($this->destino($enq, $hilo, null, null));
    }

    #[Test]
    public function con_un_solo_asunto_su_correo_vale_sin_elegir_nada(): void
    {
        // Lo que pide el caso normal: un hilo con una reserva ya tiene destino, y el botón de
        // correo sale habilitado sin que nadie toque el selector.
        $hilo = $this->hilo();
        $enq = $this->encolador($hilo, [['r-1', 'sacuna.311134@guest.booking.com']]);

        self::assertSame('sacuna.311134@guest.booking.com', $this->destino($enq, $hilo, null, null));
    }

    #[Test]
    public function el_principal_marcado_a_mano_manda_sobre_la_deduccion(): void
    {
        // Una decisión de persona pesa más que una deducción: si alguien marcó cuál es el bueno,
        // no se le lleva la contraria por tener varias reservas.
        $hilo = $this->hilo();
        $principal = new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com');
        $principal->setPrincipal(true);
        $hilo->addIdentidad($principal);

        $enq = $this->encolador($hilo, [
            ['r-1', 'sacuna.311134@guest.booking.com'],
            ['r-2', 'sacuna.672272@guest.booking.com'],
        ]);

        self::assertSame('nune@ejemplo.com', $this->destino($enq, $hilo, null, null));
    }

    #[Test]
    public function un_asunto_sin_correo_cae_al_principal(): void
    {
        // Una reserva directa no trae alias. El correo de siempre de esa persona sí sirve.
        $hilo = $this->hilo();
        $principal = new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com');
        $principal->setPrincipal(true);
        $hilo->addIdentidad($principal);

        $enq = $this->encolador($hilo, [['r-1', null]]);

        self::assertSame('nune@ejemplo.com', $this->destino($enq, $hilo, 'pms_reserva', 'r-1'));
    }

    #[Test]
    public function sin_correo_por_ningun_lado_no_hay_canal(): void
    {
        $hilo = $this->hilo();
        $enq = $this->encolador($hilo, [['r-1', null]]);

        self::assertNull($this->destino($enq, $hilo, 'pms_reserva', 'r-1'));
        self::assertFalse($enq->disponiblePara($hilo, 'pms_reserva', 'r-1'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(): MessageConversation
    {
        return new MessageConversation('pms_reserva', 'r-1');
    }

    private function destino(EmailSendEnqueuer $enq, MessageConversation $hilo, ?string $tipo, ?string $id): ?string
    {
        return new ReflectionMethod(EmailSendEnqueuer::class, 'destino')->invoke($enq, $hilo, $tipo, $id);
    }

    /** @param list<array{string, ?string}> $asuntos [contextId, correo] */
    private function encolador(MessageConversation $hilo, array $asuntos): EmailSendEnqueuer
    {
        $enlaces = array_map(fn (array $a): ConversacionEnlaceInterface => $this->enlace($a[0], $a[1]), $asuntos);

        $proveedor = new class ($enlaces) implements ProveedorDeEnlacesInterface {
            /** @param list<ConversacionEnlaceInterface> $enlaces */
            public function __construct(private readonly array $enlaces) {}

            public function getNegocio(): string { return 'prueba'; }
            public function paraConversacion(MessageConversation $c): array { return $this->enlaces; }
            public function titularDeAsunto(string $t, string $i): ?ConversacionEnlaceInterface { return null; }
            public function enlaceDeAsunto(MessageConversation $c, string $t, string $i): ?ConversacionEnlaceInterface { return null; }
        };

        return new EmailSendEnqueuer(
            $this->createStub(EntityManagerInterface::class),
            new EnlacesDeConversacion([$proveedor]),
        );
    }

    private function enlace(string $id, ?string $correo): ConversacionEnlaceInterface
    {
        return new class ($id, $correo) implements ConversacionEnlaceInterface {
            public function __construct(private readonly string $id, private readonly ?string $correo) {}

            public function getConversacion(): ?MessageConversation { return null; }
            public function getNegocio(): string { return 'prueba'; }
            public function getContextType(): string { return 'pms_reserva'; }
            public function getContextId(): string { return $this->id; }
            public function getVinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }
            public function getMilestones(): array { return []; }
            public function getOrigen(): ?string { return null; }
            public function getAgencia(): ?string { return null; }
            public function procedenciaParaElPrompt(): ?string { return null; }
            public function getCreatedAt(): ?DateTimeImmutable { return null; }
            public function getEtiqueta(): string { return 'Tu reserva ' . $this->id; }
            public function correoDeContacto(): ?string { return $this->correo; }
            public function esTitular(): bool { return true; }
            public function marcarTitular(bool $v): self { return $this; }
            public function canalesPosibles(): array { return []; }
            public function comoFrente(): Frente { return new Frente('prueba', MomentoDeFrente::Venta, 'x'); }
        };
    }
}
