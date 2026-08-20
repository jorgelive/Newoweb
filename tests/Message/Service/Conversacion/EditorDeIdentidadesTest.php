<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EditorDeIdentidades;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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

    private function editor(?MessageIdentidad $yaExistente = null): EditorDeIdentidades
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($yaExistente);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new EditorDeIdentidades($em, new NullLogger());
    }
}
