<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\MapaDeHitos;
use App\Contract\VinculoComercial;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\ProveedorDeContextoInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Enum\IdentidadTipo;
use App\Message\Factory\MessageConversationFactory;
use App\Message\Service\Conversacion\AperturaDeHilo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Abrir el hilo de alguien a quien todavía no se le ha escrito.
 *
 * ── Lo que no existía ───────────────────────────────────────────────────────
 * «Escríbele a este cliente» no era una operación. La conversación sólo nacía de rebote —al
 * guardar la reserva, al guardar el expediente, o cuando el cliente escribía primero— y si se
 * cortaba, no había forma de volver a abrirla desde el panel. Para los proveedores no nacía
 * nunca.
 */
final class AperturaDeHiloTest extends TestCase
{
    #[Test]
    public function un_dominio_sin_proveedor_lo_dice_con_su_nombre(): void
    {
        // El operador tiene que poder distinguir «esto todavía no está» de «algo falló».
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Todavía no se pueden abrir.*travel_organizacion/');

        $this->apertura()->abrir('travel_organizacion', 'org-1');
    }

    #[Test]
    public function un_asunto_que_ya_no_existe_se_distingue_del_dominio_que_falta(): void
    {
        // Mismo síntoma —no hay hilo— y causas opuestas: aquí el dominio sí sabe, y lo que no
        // está es la reserva. Un solo mensaje para los dos casos manda a buscar donde no es.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya no existe/');

        $this->apertura([$this->proveedor('pms_reserva', null)])->abrir('pms_reserva', 'r-fantasma');
    }

    #[Test]
    public function sin_ningun_dato_de_contacto_NO_se_abre(): void
    {
        // ⚠️ Un hilo que no resuelve a nadie no recibe, no sale, y ensucia la bandeja con una
        // fila que nadie puede cerrar. Es preferible negarse y decir qué falta.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ni teléfono ni correo/');

        $this->apertura([$this->proveedor('pms_reserva', $this->contexto([]))])->abrir('pms_reserva', 'r-1');
    }

    #[Test]
    public function con_un_telefono_basta(): void
    {
        $contexto = $this->contexto([IdentidadTipo::TELEFONO->value => '51984123456']);
        $esperado = new MessageConversation('pms_reserva', 'r-1');

        $factoria = $this->createMock(MessageConversationFactory::class);
        $factoria->expects(self::once())
            ->method('upsertFromContext')
            ->with($contexto, true)   // flush: la abre alguien que está esperando delante
            ->willReturn($esperado);

        $apertura = new AperturaDeHilo($factoria, new NullLogger(), [$this->proveedor('pms_reserva', $contexto)]);

        self::assertSame($esperado, $apertura->abrir('pms_reserva', 'r-1'));
    }

    #[Test]
    public function pregunta_al_proveedor_que_soporta_el_tipo_y_no_al_primero(): void
    {
        // Con tres dominios enchufados por tag, el orden lo decide el compilador. Si esto se
        // saltara el `supports()`, abrir una organización pediría el contexto al PMS.
        $contexto = $this->contexto([IdentidadTipo::EMAIL->value => 'hotel@ejemplo.com']);

        $factoria = $this->createStub(MessageConversationFactory::class);
        $factoria->method('upsertFromContext')->willReturn(new MessageConversation('travel_organizacion', 'org-1'));

        $apertura = new AperturaDeHilo($factoria, new NullLogger(), [
            $this->proveedor('pms_reserva', $this->contexto([])),          // no soporta: si lo eligiera, lanzaría
            $this->proveedor('travel_organizacion', $contexto),
        ]);

        self::assertSame('travel_organizacion', $apertura->abrir('travel_organizacion', 'org-1')->getContextType());
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<ProveedorDeContextoInterface> $proveedores */
    private function apertura(array $proveedores = []): AperturaDeHilo
    {
        return new AperturaDeHilo(
            $this->createStub(MessageConversationFactory::class),
            new NullLogger(),
            $proveedores
        );
    }

    private function proveedor(string $tipo, ?MessageContextInterface $contexto): ProveedorDeContextoInterface
    {
        return new class ($tipo, $contexto) implements ProveedorDeContextoInterface {
            public function __construct(
                private readonly string $tipo,
                private readonly ?MessageContextInterface $contexto,
            ) {}

            public function supports(string $contextType): bool { return $contextType === $this->tipo; }
            public function para(string $contextId): ?MessageContextInterface { return $this->contexto; }
        };
    }

    /** @param array<string, string> $identificadores */
    private function contexto(array $identificadores): MessageContextInterface
    {
        return new class ($identificadores) implements MessageContextInterface {
            /** @param array<string, string> $identificadores */
            public function __construct(private readonly array $identificadores) {}

            public function getContextType(): string { return 'pms_reserva'; }
            public function getContextId(): string { return 'r-1'; }
            public function getContextLanguage(): string { return 'es'; }
            public function getContextName(): ?string { return 'Alguien'; }
            public function getContextPhone(): ?string { return null; }
            public function getIdentificadores(): array { return $this->identificadores; }
            public function getOrigin(): ?string { return 'directo'; }
            public function getStatusTag(): ?string { return null; }
            public function getVinculo(): VinculoComercial { return VinculoComercial::Cliente; }
            public function getAgencyId(): ?string { return null; }
            public function getMilestones(): MapaDeHitos { return MapaDeHitos::vacio(); }
            public function getItems(): array { return []; }
            public function getFinancialTotal(): ?float { return null; }
            public function isFinancialCleared(): bool { return true; }
            public function isCancelled(): bool { return false; }
        };
    }
}
