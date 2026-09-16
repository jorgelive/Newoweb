<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use PHPUnit\Framework\TestCase;

/**
 * Un documento vencido no se puede dar por bueno mirándolo.
 *
 * 🔥 Es la única nota que ninguna firma humana levanta. Las demás —la banda cortada, la ficha
 * copiada del escaneo— las resuelve alguien que abre el documento y comprueba los datos; por mucho
 * que se mire, un DNI vencido sigue vencido y en el mostrador no vale.
 */
final class IdentificacionVencidaTest extends TestCase
{
    private function con(?string $vencimiento): CotizacionPasajeroIdentificacion
    {
        $i = new CotizacionPasajeroIdentificacion();

        return $vencimiento === null ? $i : $i->setVencimiento(new \DateTimeImmutable($vencimiento));
    }

    public function testUnDocumentoCaducadoLoDice(): void
    {
        self::assertTrue($this->con('2026-08-30')->estaVencida(new \DateTimeImmutable('2026-09-16')));
    }

    public function testUnoVigenteNo(): void
    {
        self::assertFalse($this->con('2036-08-11')->estaVencida(new \DateTimeImmutable('2026-09-16')));
    }

    /** ⚠️ El que vence HOY sirve HOY. Comparar con la hora lo daría por caducado desde las 00:00:01. */
    public function testElQueVenceHoySirveHoy(): void
    {
        self::assertFalse($this->con('2026-09-16')->estaVencida(new \DateTimeImmutable('2026-09-16 23:59:59')));
    }

    /** Sin fecha es «no lo sabemos», no «caducado»: acusarlo llenaría la cola de ruido. */
    public function testSinFechaNoEstaVencido(): void
    {
        self::assertFalse($this->con(null)->estaVencida(new \DateTimeImmutable('2026-09-16')));
    }
}
