<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Message\Entity\MessageConversation;
use App\Message\Enum\IdentidadTipo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La fusión sugerida: un identificador llegó por el dominio y ya era de otro hilo.
 *
 * Lo que importa es CUÁNDO es noticia. El recálculo de una reserva pasa por el resolutor varias
 * veces al día, y quien escucha el `true` de `sugerirFusion()` avisa al equipo: si cada pasada
 * fuera «nueva», sería un aviso por recálculo; y si lo descartado volviera, el «no es la misma
 * persona» no serviría de nada.
 */
final class FusionSugeridaTest extends TestCase
{
    #[Test]
    public function la_primera_vez_es_noticia_y_las_siguientes_no(): void
    {
        [$nuevo, $viejo] = [$this->hilo(), $this->hilo('Adrián Tolaba')];

        self::assertTrue($nuevo->sugerirFusion($viejo, IdentidadTipo::TELEFONO, '5493884040780', $this->ahora()));
        self::assertFalse($nuevo->sugerirFusion($viejo, IdentidadTipo::TELEFONO, '5493884040780', $this->ahora()));
        self::assertSame('Adrián Tolaba', $nuevo->getFusionSugerida()['nombre'] ?? null);
    }

    #[Test]
    public function lo_descartado_no_vuelve_a_sugerirse(): void
    {
        [$nuevo, $viejo] = [$this->hilo(), $this->hilo()];
        $nuevo->sugerirFusion($viejo, IdentidadTipo::TELEFONO, '51984123456', $this->ahora());

        $nuevo->descartarFusionSugerida();

        self::assertNull($nuevo->getFusionSugerida());
        self::assertFalse($nuevo->sugerirFusion($viejo, IdentidadTipo::TELEFONO, '51984123456', $this->ahora()));
        self::assertNull($nuevo->getFusionSugerida());
    }

    #[Test]
    public function descartar_uno_no_impide_sugerir_otro(): void
    {
        [$nuevo, $uno, $otro] = [$this->hilo(), $this->hilo(), $this->hilo()];
        $nuevo->sugerirFusion($uno, IdentidadTipo::TELEFONO, '51984123456', $this->ahora());
        $nuevo->descartarFusionSugerida();

        self::assertTrue($nuevo->sugerirFusion($otro, IdentidadTipo::EMAIL, 'a@b.pe', $this->ahora()));
    }

    #[Test]
    public function unirlos_olvida_la_sugerencia_con_ese_hilo_y_no_otra(): void
    {
        [$nuevo, $viejo, $tercero] = [$this->hilo(), $this->hilo(), $this->hilo()];
        $nuevo->sugerirFusion($viejo, IdentidadTipo::TELEFONO, '51984123456', $this->ahora());

        $nuevo->olvidarFusionCon($tercero);
        self::assertNotNull($nuevo->getFusionSugerida());

        $nuevo->olvidarFusionCon($viejo);
        self::assertNull($nuevo->getFusionSugerida());
    }

    #[Test]
    public function un_json_con_otra_forma_se_lee_como_que_no_hay(): void
    {
        $hilo = $this->hilo();
        (new \ReflectionProperty(MessageConversation::class, 'fusionSugerida'))->setValue($hilo, ['con' => 7]);

        self::assertNull($hilo->getFusionSugerida());
    }

    private function hilo(?string $nombre = null): MessageConversation
    {
        return (new MessageConversation('pms_reserva', 'r-' . uniqid()))->setGuestName($nombre);
    }

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-02 09:00:00');
    }
}
