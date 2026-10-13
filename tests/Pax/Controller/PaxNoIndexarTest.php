<?php

declare(strict_types=1);

namespace App\Tests\Pax\Controller;

use App\Pax\Controller\PaxAppController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué páginas de pax pueden indexar los buscadores: sólo la vitrina de una casita. Ver
 * `PaxAppController::PRIVADAS`.
 */
final class PaxNoIndexarTest extends TestCase
{
    #[Test]
    public function la_vitrina_de_una_casita_es_publica(): void
    {
        self::assertTrue(PaxAppController::esVitrina('/centro-cusco-inti/centro-cusco-inti-casita-1'));
    }

    #[Test]
    public function lo_que_se_comparte_por_enlace_no_se_indexa(): void
    {
        foreach ([
            '/huesped/reserva/2YR4SJ',          // la guía, con las instrucciones de entrada
            '/huesped/reserva/2YR4SJ/guia',
            '/pago/abc123',                     // un enlace de pago
            '/catalogo/7DUZHZ',                 // un catálogo hecho para alguien
            '/catalogo/7DUZHZ/p/1',
            '/file/X5ZXF4',                     // un expediente (dos tramos: no es vitrina)
            '/',
            '/cualquier/cosa/mas/larga',
        ] as $ruta) {
            self::assertFalse(PaxAppController::esVitrina($ruta), $ruta);
        }
    }
}
