<?php

declare(strict_types=1);

namespace App\Tests\Finanzas;

use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Entity\FinPasarelaCobroAudit;
use App\Finanzas\Enum\FinPasarela;
use App\Finanzas\Service\FinCobroAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Lo que el navegador cuenta del reto 3DS. Ver `FinCobroAuditor::anotarReto()`.
 *
 * El detalle llega de un endpoint público: se recorta, y escribir nunca puede lanzar.
 */
final class FinCobroAuditorRetoTest extends TestCase
{
    #[Test]
    public function el_evento_queda_como_desenlace_con_3ds_y_el_detalle_recortado(): void
    {
        $persistidas = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidas): void {
            $persistidas[] = $o;
        });

        (new FinCobroAuditor($em, new NullLogger()))
            ->anotarReto($this->enlace(), FinPasarela::CULQI, FinPasarelaCobroAudit::DESENLACE_RETO_ERROR, str_repeat('x', 900));

        self::assertCount(1, $persistidas);
        $audit = $persistidas[0];
        self::assertInstanceOf(FinPasarelaCobroAudit::class, $audit);
        self::assertSame('reto_error', $audit->getDesenlace());
        self::assertTrue($audit->isCon3DS());
        self::assertSame(500, mb_strlen((string) $audit->getMotivo()));
    }

    #[Test]
    public function sin_detalle_no_hay_motivo(): void
    {
        $persistidas = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistidas): void {
            $persistidas[] = $o;
        });

        (new FinCobroAuditor($em, new NullLogger()))
            ->anotarReto($this->enlace(), FinPasarela::CULQI, FinPasarelaCobroAudit::DESENLACE_RETO_LANZADO, '');

        self::assertInstanceOf(FinPasarelaCobroAudit::class, $persistidas[0]);
        self::assertNull($persistidas[0]->getMotivo());
    }

    #[Test]
    public function si_la_escritura_falla_no_lanza(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new RuntimeException('base caída'));

        (new FinCobroAuditor($em, new NullLogger()))
            ->anotarReto($this->enlace(), FinPasarela::CULQI, FinPasarelaCobroAudit::DESENLACE_RETO_ABANDONADO, null);

        $this->expectNotToPerformAssertions();
    }

    /** Cada evento cabe en la columna `desenlace` (`length: 20`). */
    #[Test]
    public function los_eventos_del_navegador_caben_en_la_columna(): void
    {
        foreach (FinPasarelaCobroAudit::DESENLACES_DEL_NAVEGADOR as $evento) {
            self::assertLessThanOrEqual(20, strlen($evento), $evento);
        }
    }

    private function enlace(): FinEnlacePago
    {
        return (new \ReflectionClass(FinEnlacePago::class))->newInstanceWithoutConstructor();
    }
}
