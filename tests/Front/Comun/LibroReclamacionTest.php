<?php

declare(strict_types=1);

namespace App\Tests\Front\Comun;

use App\Front\Comun\Entity\LibroReclamacion;
use App\Front\Comun\Service\LibroReclamacionesService;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas puras del Libro de Reclamaciones (docs/WebPublica.md §5): numeración y plazos.
 */
final class LibroReclamacionTest extends TestCase
{
    public function testElCorrelativoReiniciaCadaAnio(): void
    {
        $fecha = new \DateTimeImmutable('2026-10-07 10:00');

        self::assertSame('2026-00001', LibroReclamacionesService::siguiente($fecha, null));
        self::assertSame('2026-00008', LibroReclamacionesService::siguiente($fecha, '2026-00007'));
        // El último del año anterior no arrastra la cuenta.
        self::assertSame('2027-00001', LibroReclamacionesService::siguiente(new \DateTimeImmutable('2027-01-02'), '2026-00412'));
    }

    public function testElPlazoDeRespuestaCuentaQuinceDiasHabiles(): void
    {
        $hoja = (new LibroReclamacion())->setFecha(new \DateTimeImmutable('2026-10-07 18:30')); // miércoles

        // 15 hábiles desde el día siguiente: tres semanas exactas, otro miércoles.
        self::assertSame('2026-10-28', $hoja->getVenceRespuesta()->format('Y-m-d'));

        $viernes = (new LibroReclamacion())->setFecha(new \DateTimeImmutable('2026-10-09 09:00'));
        self::assertSame('2026-10-30', $viernes->getVenceRespuesta()->format('Y-m-d'));
    }

    public function testUnaRespuestaEnBlancoNoPoneFecha(): void
    {
        $hoja = (new LibroReclamacion())->setRespuesta('   ');

        self::assertNull($hoja->getRespuesta());
        self::assertNull($hoja->getFechaRespuesta());
    }

    public function testLaFechaDeRespuestaLaPoneLaPrimeraRespuestaYNoSeMueve(): void
    {
        $hoja = (new LibroReclamacion())->setRespuesta('Lamentamos lo ocurrido…');
        $primera = $hoja->getFechaRespuesta();
        self::assertNotNull($primera);

        $hoja->setRespuesta('Corrijo: lamentamos lo ocurrido.');
        self::assertSame($primera, $hoja->getFechaRespuesta());
    }
}
