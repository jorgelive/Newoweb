<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\MapaDeHitos;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\MessageContextInterface;
use App\Message\Contract\ProveedorDeContextoInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\ContactoDelAsunto;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A qué teléfono y correo se le escribe a un asunto.
 *
 * ── Lo que decide todo: identidad o semilla ─────────────────────────────────
 * El dato que guarda el asunto es la semilla con la que nació la identidad. Una vez que la
 * identidad existe, manda ella — y el panel deja de ofrecer el campo, porque editarlo ahí se
 * guarda y no cambia a dónde sale el mensaje.
 */
final class ContactoDelAsuntoTest extends TestCase
{
    #[Test]
    public function con_hilo_manda_la_identidad_y_lo_dice(): void
    {
        $hilo = $this->hilo(telefono: '51984111222', correo: 'real@ejemplo.com');

        $r = $this->resolver($hilo, semillas: [
            IdentidadTipo::TELEFONO->value => '51900000000',
            IdentidadTipo::EMAIL->value => 'viejo@ejemplo.com',
        ]);

        self::assertSame('51984111222', $r['telefono']);
        self::assertSame('identidad', $r['telefonoOrigen']);
        self::assertSame('real@ejemplo.com', $r['correo']);
        self::assertSame('identidad', $r['correoOrigen']);
    }

    #[Test]
    public function sin_hilo_cae_a_la_semilla_MARCADA_como_tal(): void
    {
        // ⚠️ El origen no se puede deducir comparando valores: lo normal es que coincidan
        // —la identidad se sembró de la semilla— así que compararlos diría «semilla» justo
        // cuando sí hay identidad. Lo tiene que contestar quien lo resolvió.
        $r = $this->resolver(null, semillas: [
            IdentidadTipo::TELEFONO->value => '51900000000',
            IdentidadTipo::EMAIL->value => 'semilla@ejemplo.com',
        ]);

        self::assertSame('51900000000', $r['telefono']);
        self::assertSame('semilla', $r['telefonoOrigen']);
        self::assertSame('semilla@ejemplo.com', $r['correo']);
        self::assertSame('semilla', $r['correoOrigen']);
    }

    #[Test]
    public function una_identidad_VETADA_no_es_el_dato_de_contacto(): void
    {
        // Es el que NO hay que usar. Se prefiere caer a la semilla —que al menos es un dato—
        // antes que ofrecer un número muerto y que alguien lo marque en el teléfono.
        $hilo = $this->hilo(telefono: '51984111222', correo: null, telefonoVetado: true);

        $r = $this->resolver($hilo, semillas: [IdentidadTipo::TELEFONO->value => '51900000000']);

        self::assertSame('51900000000', $r['telefono']);
        self::assertSame('semilla', $r['telefonoOrigen']);
    }

    #[Test]
    public function cada_dato_resuelve_por_su_cuenta(): void
    {
        // Caso corriente del expediente de la captura: teléfono sí, correo vacío. Que falte uno
        // no puede arrastrar al otro a la semilla.
        $hilo = $this->hilo(telefono: '51984111222', correo: null);

        $r = $this->resolver($hilo, semillas: [IdentidadTipo::EMAIL->value => 'solo@semilla.com']);

        self::assertSame('identidad', $r['telefonoOrigen']);
        self::assertSame('solo@semilla.com', $r['correo']);
        self::assertSame('semilla', $r['correoOrigen']);
    }

    #[Test]
    public function sin_hilo_y_sin_semilla_no_hay_nada_que_decir(): void
    {
        $r = $this->resolver(null, semillas: []);

        self::assertNull($r['telefono']);
        self::assertNull($r['telefonoOrigen']);
        self::assertNull($r['correo']);
        self::assertNull($r['correoOrigen']);
        self::assertNull($r['conversacionId']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(?string $telefono, ?string $correo, bool $telefonoVetado = false): MessageConversation
    {
        $hilo = new MessageConversation('cotizacion_file', 'f-1');

        if ($telefono !== null) {
            $identidad = new MessageIdentidad(IdentidadTipo::TELEFONO, $telefono);
            $identidad->setPrincipal(true);
            if ($telefonoVetado) { $identidad->bloquear('probando'); }
            $hilo->addIdentidad($identidad);
        }

        if ($correo !== null) {
            $identidad = new MessageIdentidad(IdentidadTipo::EMAIL, $correo);
            $identidad->setPrincipal(true);
            $hilo->addIdentidad($identidad);
        }

        return $hilo;
    }

    /**
     * @param array<string, string> $semillas
     * @return array{telefono: ?string, telefonoOrigen: ?string, correo: ?string, correoOrigen: ?string, conversacionId: ?string}
     */
    private function resolver(?MessageConversation $hilo, array $semillas): array
    {
        $proveedorEnlaces = new class ($hilo) implements ProveedorDeEnlacesInterface {
            public function __construct(private readonly ?MessageConversation $hilo) {}

            public function getNegocio(): string { return 'prueba'; }
            public function paraConversacion(MessageConversation $c): array { return []; }
            public function enlaceDeAsunto(MessageConversation $c, string $t, string $i): ?ConversacionEnlaceInterface { return null; }

            public function titularDeAsunto(string $t, string $i): ?ConversacionEnlaceInterface
            {
                return $this->hilo === null ? null : new class ($this->hilo) implements ConversacionEnlaceInterface {
                    public function __construct(private readonly MessageConversation $hilo) {}

                    public function getConversacion(): ?MessageConversation { return $this->hilo; }
                    public function getNegocio(): string { return 'prueba'; }
                    public function getContextType(): string { return 'cotizacion_file'; }
                    public function getContextId(): string { return 'f-1'; }
                    public function getVinculo(): VinculoComercial { return VinculoComercial::Cliente; }
                    public function getMomento(): \App\Contract\MomentoDeFrente { return \App\Contract\MomentoDeFrente::Venta; }
                    public function getMilestones(): array { return []; }
                    public function getOrigen(): ?string { return null; }
                    public function getAgencia(): ?string { return null; }
                    public function procedenciaParaElPrompt(): ?string { return null; }
                    public function getCreatedAt(): ?DateTimeImmutable { return null; }
                    public function getEtiqueta(): string { return 'x'; }
                    public function correoDeContacto(): ?string { return null; }
                    public function correoEsExclusivo(): bool { return false; }
                    public function esTitular(): bool { return true; }
                    public function marcarTitular(bool $v): self { return $this; }
                    public function canalesPosibles(): array { return []; }
                    public function comoFrente(): \App\Contract\Frente { return new \App\Contract\Frente('prueba', \App\Contract\MomentoDeFrente::Venta, 'x'); }
                };
            }
        };

        $proveedorContexto = new class ($semillas) implements ProveedorDeContextoInterface {
            /** @param array<string, string> $semillas */
            public function __construct(private readonly array $semillas) {}

            public function supports(string $contextType): bool { return $contextType === 'cotizacion_file'; }

            public function para(string $contextId): ?MessageContextInterface
            {
                return new class ($this->semillas) implements MessageContextInterface {
                    /** @param array<string, string> $semillas */
                    public function __construct(private readonly array $semillas) {}

                    public function getContextType(): string { return 'cotizacion_file'; }
                    public function getContextId(): string { return 'f-1'; }
                    public function getContextLanguage(): string { return 'es'; }
                    public function getContextName(): ?string { return null; }
                    public function getContextPhone(): ?string { return null; }
                    public function getIdentificadores(): array { return $this->semillas; }
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
        };

        return new ContactoDelAsunto(new EnlacesDeConversacion([$proveedorEnlaces]), [$proveedorContexto])
            ->para('cotizacion_file', 'f-1');
    }
}
