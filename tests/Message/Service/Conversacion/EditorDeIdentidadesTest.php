<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EditorDeIdentidades;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Poder decir CUÁL número se bloquea, se retira o es el bueno.
 *
 * Hasta ahora los identificadores sólo entraban —`upsertFromContext()` los registra en cada
 * recálculo— y no salían nunca. Con eso, un número mal tecleado se queda reclamando el hilo para
 * siempre; y si pertenece a una persona real, su WhatsApp entra en el hilo del huésped.
 */
final class EditorDeIdentidadesTest extends TestCase
{
    #[Test]
    public function anadir_normaliza_y_cuelga_del_hilo(): void
    {
        $hilo = $this->hilo();

        $identidad = $this->editor()->anadir($hilo, IdentidadTipo::TELEFONO, '+51 984 12 34 56');

        self::assertSame('51984123456', $identidad->getValor());
        self::assertCount(1, $hilo->getIdentidades());
    }

    #[Test]
    public function un_valor_de_otro_hilo_no_se_roba_se_avisa(): void
    {
        // ⚠️ `(tipo, valor)` es único: moverlo dejaría mudo el historial del otro. Y si de
        // verdad son la misma persona, lo que toca es FUNDIR, que decide una persona. Sin este
        // corte el operador se comería un 500 por unicidad haciendo lo correcto.
        $ajena = new MessageConversation('pms_reserva', 'r-9');
        $ajena->setGuestName('Otra persona');
        $ocupada = new MessageIdentidad(IdentidadTipo::TELEFONO, '51984123456');
        $ajena->addIdentidad($ocupada);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/fusionar-hilos/');

        $this->editor($ocupada)->anadir($this->hilo(), IdentidadTipo::TELEFONO, '51984123456');
    }

    #[Test]
    public function volver_a_anadir_uno_retirado_lo_revive(): void
    {
        // La lápida frena al DOMINIO, que re-registra sin criterio. A quien rectifica a mano no.
        $hilo = $this->hilo();
        $identidad = new MessageIdentidad(IdentidadTipo::TELEFONO, '51984123456');
        $hilo->addIdentidad($identidad);
        $identidad->retirar(new DateTimeImmutable('2026-08-20 12:00:00'));

        $this->editor($identidad)->anadir($hilo, IdentidadTipo::TELEFONO, '51984123456');

        self::assertTrue($identidad->estaViva());
        self::assertCount(1, $hilo->getIdentidades(), 'Se revive la fila, no se crea otra.');
    }

    #[Test]
    public function marcar_principal_quita_la_marca_a_las_demas(): void
    {
        // Con dos principales habría que elegir por orden de llegada, que no es una decisión:
        // es un accidente reproducible.
        $hilo = $this->hilo();
        $uno = $this->telefono($hilo, '51984111111');
        $dos = $this->telefono($hilo, '51984222222');
        $uno->setPrincipal(true);

        $this->editor()->marcarPrincipal($dos);

        self::assertFalse($uno->isPrincipal());
        self::assertTrue($dos->isPrincipal());
    }

    #[Test]
    public function un_retirado_no_puede_ser_la_salida_por_defecto(): void
    {
        $hilo = $this->hilo();
        $identidad = $this->telefono($hilo, '51984111111');
        $identidad->retirar(new DateTimeImmutable('2026-08-20 12:00:00'));

        $this->expectException(RuntimeException::class);

        $this->editor()->marcarPrincipal($identidad);
    }

    #[Test]
    public function vetar_un_numero_de_dos_no_apaga_el_canal_del_hilo(): void
    {
        // El caso entero: el huésped da un número equivocado y tiene otro que sí funciona.
        $hilo = $this->hilo();
        $malo = $this->telefono($hilo, '51984111111');
        $this->telefono($hilo, '51984222222');

        $this->editor()->bloquear($malo, true);

        self::assertTrue($malo->isBloqueado());
        self::assertFalse($hilo->isWhatsappDisabled(), 'Le queda un número vivo.');
    }

    #[Test]
    public function retirar_el_unico_vetado_devuelve_el_canal_al_hilo(): void
    {
        $hilo = $this->hilo();
        $malo = $this->telefono($hilo, '51984111111');
        $editor = $this->editor();

        $editor->bloquear($malo, true);
        self::assertTrue($hilo->isWhatsappDisabled());

        $editor->retirar($malo, new DateTimeImmutable('2026-08-20 12:00:00'));

        self::assertFalse($hilo->isWhatsappDisabled(), 'Sin teléfonos vivos no es veto: es falta de datos.');
        self::assertFalse($malo->isPrincipal());
    }

    #[Test]
    public function un_valor_irreconocible_no_entra(): void
    {
        $this->expectException(RuntimeException::class);

        $this->editor()->anadir($this->hilo(), IdentidadTipo::TELEFONO, '   ');
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function un_alias_de_plataforma_no_puede_ser_la_salida_por_defecto(): void
    {
        // Booking emite uno POR RESERVA. Marcarlo como principal mandaría la estancia de
        // Airbnb del mes que viene al buzón de la de Booking del mes pasado.
        $hilo = $this->hilo();
        $alias = new MessageIdentidad(IdentidadTipo::EMAIL, 'sacuna.311134@guest.booking.com');
        $hilo->addIdentidad($alias);

        $editor = $this->editor(null, [['sacuna.311134@guest.booking.com', true]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no es suyo/');

        $editor->marcarPrincipal($alias);
    }

    #[Test]
    public function el_correo_de_una_reserva_directa_si_puede_ser_principal(): void
    {
        // El mismo camino, con el asunto declarándose NO exclusivo: ahí el correo es de verdad
        // de la persona y marcarlo es justo lo que se espera del editor.
        $hilo = $this->hilo();
        $suyo = new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com');
        $hilo->addIdentidad($suyo);

        $this->editor(null, [['nune@ejemplo.com', false]])->marcarPrincipal($suyo);

        self::assertTrue($suyo->isPrincipal());
    }

    #[Test]
    public function un_telefono_no_lo_frena_el_alias_de_un_correo(): void
    {
        // La guarda es sólo para correos: un teléfono alcanza a la PERSONA pase lo que pase con
        // los asuntos. Sin la comprobación de tipo, un hilo con reserva de OTA se quedaría sin
        // poder marcar su móvil bueno.
        $hilo = $this->hilo();
        $movil = $this->telefono($hilo, '+51984123456');

        $this->editor(null, [['sacuna.311134@guest.booking.com', true]])->marcarPrincipal($movil);

        self::assertTrue($movil->isPrincipal());
    }

    private function hilo(): MessageConversation
    {
        return new MessageConversation('pms_reserva', 'r-1');
    }

    private function telefono(MessageConversation $hilo, string $valor): MessageIdentidad
    {
        $identidad = new MessageIdentidad(IdentidadTipo::TELEFONO, $valor);
        $hilo->addIdentidad($identidad);

        return $identidad;
    }

    /**
     * @param list<array{string, bool}> $asuntos [correo del asunto, ¿exclusivo?]
     */
    private function editor(?MessageIdentidad $yaExistente = null, array $asuntos = []): EditorDeIdentidades
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($yaExistente);

        $em = $this->createStub(EntityManagerInterface::class);
        // `ResolutorDeHilo` mira lo pendiente de insertar antes que la base: sin este doble,
        // `getUnitOfWork()` devuelve null y revienta. Vacío = «nada a medio guardar».
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getRepository')->willReturn($repo);

        return new EditorDeIdentidades($em, new NullLogger(), $this->enlaces($asuntos));
    }

    /**
     * Los asuntos del hilo, con lo único que mira el editor: su correo y si es exclusivo.
     *
     * @param list<array{string, bool}> $asuntos
     */
    private function enlaces(array $asuntos): EnlacesDeConversacion
    {
        $enlaces = array_map(
            static fn (array $a): ConversacionEnlaceInterface => new class ($a[0], $a[1]) implements ConversacionEnlaceInterface {
                public function __construct(private readonly string $correo, private readonly bool $exclusivo) {}

                public function getConversacion(): ?MessageConversation { return null; }
                public function getNegocio(): string { return 'prueba'; }
                public function getContextType(): string { return 'pms_reserva'; }
                public function getContextId(): string { return 'r-1'; }
                public function getVinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
                public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }
                public function getMilestones(): array { return []; }
                public function getOrigen(): ?string { return null; }
                public function getAgencia(): ?string { return null; }
                public function procedenciaParaElPrompt(): ?string { return null; }
                public function getCreatedAt(): ?DateTimeImmutable { return null; }
                public function getEtiqueta(): string { return 'Tu reserva'; }
                public function correoDeContacto(): ?string { return $this->correo; }
                public function correoEsExclusivo(): bool { return $this->exclusivo; }
                public function esTitular(): bool { return true; }
                public function marcarTitular(bool $v): self { return $this; }
                public function canalesPosibles(): array { return []; }
                public function comoFrente(): Frente { return new Frente('prueba', MomentoDeFrente::Venta, 'x'); }
            },
            $asuntos
        );

        $proveedor = new class ($enlaces) implements ProveedorDeEnlacesInterface {
            /** @param list<ConversacionEnlaceInterface> $enlaces */
            public function __construct(private readonly array $enlaces) {}

            public function getNegocio(): string { return 'prueba'; }
            public function paraConversacion(MessageConversation $c): array { return $this->enlaces; }
            public function titularDeAsunto(string $t, string $i): ?ConversacionEnlaceInterface { return null; }
            public function enlaceDeAsunto(MessageConversation $c, string $t, string $i): ?ConversacionEnlaceInterface { return null; }
        };

        return new EnlacesDeConversacion([$proveedor]);
    }
}
