<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum as V;
use PHPUnit\Framework\TestCase;

/**
 * Un re-juicio de la máquina no puede deshacer lo que aceptó una persona… mientras sea lo mismo.
 *
 * 🔥 La regla vivía sólo en la tanda del manifiesto: «Reprocesar» y el comando la pisaban, y una
 * aceptación duraba un clic. Ahora vive en `CotizacionFilearchivo::rejuzgar()` y esto la fija.
 */
final class RejuzgarRespetaLaAceptacionTest extends TestCase
{
    private const VUELO = ['campo' => 'vuelo de entrada', 'documento' => 'CM177', 'manifiesto' => 'DM6771'];
    private const FECHA = ['campo' => 'fecha de salida', 'documento' => '2026-09-21', 'manifiesto' => '2026-09-22'];

    /** @param list<array{campo: string, documento: string, manifiesto: string}> $discrepancias */
    private function aceptado(array $discrepancias): CotizacionFilearchivo
    {
        return (new CotizacionFilearchivo())
            ->registrarValidacion(V::CONFIRMADO, $discrepancias, ['revisado y aceptado por ana']);
    }

    public function testElMismoDesacuerdoConservaLaFirma(): void
    {
        $a = $this->aceptado([self::VUELO]);

        self::assertFalse($a->rejuzgar(V::OBSERVADO, [self::VUELO], ['otra nota']));
        self::assertSame(V::CONFIRMADO, $a->getEstadoValidacion());
        self::assertSame(['revisado y aceptado por ana'], $a->getNotasValidacion());
    }

    /** ⚠️ La tanda comparaba con `==` sobre la lista: el orden reabría una aceptación sin motivo. */
    public function testElOrdenDeLasDiscrepanciasNoReabreNada(): void
    {
        $a = $this->aceptado([self::VUELO, self::FECHA]);

        self::assertFalse($a->rejuzgar(V::OBSERVADO, [self::FECHA, self::VUELO], []));
        self::assertSame(V::CONFIRMADO, $a->getEstadoValidacion());
    }

    public function testUnDesacuerdoNuevoReabre(): void
    {
        $a = $this->aceptado([self::VUELO]);

        self::assertTrue($a->rejuzgar(V::OBSERVADO, [self::VUELO, self::FECHA], []));
        self::assertSame(V::OBSERVADO, $a->getEstadoValidacion());
    }

    public function testSiElProblemaDesaparecePasaAVerde(): void
    {
        $a = $this->aceptado([self::VUELO]);

        self::assertTrue($a->rejuzgar(V::VALIDADO_OCR, [], []));
        self::assertSame(V::VALIDADO_OCR, $a->getEstadoValidacion());
    }

    /** Lo firmado ya no se puede comparar: la firma no puede quedarse apoyada en nada. */
    public function testSiYaNoSePuedeCompararSeDiceAunqueEsteAceptado(): void
    {
        $a = $this->aceptado([self::VUELO]);

        self::assertTrue($a->rejuzgar(V::NO_VALIDADO, [], ['no se puede cotejar: sin subgrupo aéreo']));
        self::assertSame(V::NO_VALIDADO, $a->getEstadoValidacion());
    }

    public function testSinAceptacionSeAplicaSiempre(): void
    {
        $a = (new CotizacionFilearchivo())->registrarValidacion(V::OBSERVADO, [self::VUELO], []);

        self::assertTrue($a->rejuzgar(V::OBSERVADO, [self::VUELO], ['nota nueva']));
        self::assertSame(['nota nueva'], $a->getNotasValidacion());
    }
}
