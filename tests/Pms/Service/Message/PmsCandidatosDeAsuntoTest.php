<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Pms\Service\Message\PmsCandidatosDeAsunto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** La respuesta a «¿a nombre de quién está la reserva?» sube esa reserva arriba (Carla, 12/09/2026). */
#[CoversClass(PmsCandidatosDeAsunto::class)]
final class PmsCandidatosDeAsuntoTest extends TestCase
{
    public function testElNombreQueDaSinAcentosNiMayusculas(): void
    {
        $chat = PmsCandidatosDeAsunto::normalizar('Está a nombre de BRUNA, gracias');

        self::assertTrue(PmsCandidatosDeAsunto::laNombra($chat, 'Bruna', 'Coelho'));
        self::assertTrue(PmsCandidatosDeAsunto::laNombra(PmsCandidatosDeAsunto::normalizar('la reserva es de Théa'), 'Thea', 'Landeau'));
    }

    public function testPalabraEnteraYNoTrozos(): void
    {
        $chat = PmsCandidatosDeAsunto::normalizar('Hola, les escribo sobre mi reserva. ¿Brunaldo?');

        self::assertFalse(PmsCandidatosDeAsunto::laNombra($chat, 'Bruna', 'Coelho'));
        // «de» del apellido no cuenta: es demasiado corta para decir nada.
        self::assertFalse(PmsCandidatosDeAsunto::laNombra($chat, 'Ana', 'de la Cruz'));
    }
}
