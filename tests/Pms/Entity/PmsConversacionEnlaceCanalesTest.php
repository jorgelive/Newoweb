<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsConversacionEnlace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Un acompañante no se alcanza por Beds24: ese chat es el de la OTA del titular. Con la cabecera
 * de su hilo apuntando a la reserva, un mensaje a Carla habría aterrizado en la bandeja de
 * Booking de Bruna.
 */
#[CoversClass(PmsConversacionEnlace::class)]
final class PmsConversacionEnlaceCanalesTest extends TestCase
{
    public function testElTitularNoSeAcota(): void
    {
        self::assertSame([], (new PmsConversacionEnlace())->canalesPosibles());
    }

    public function testElAcompananteSoloPorLosSuyos(): void
    {
        $enlace = (new PmsConversacionEnlace())->setEsTitular(false);

        self::assertSame(['whatsapp_meta', 'email'], $enlace->canalesPosibles());
    }
}
