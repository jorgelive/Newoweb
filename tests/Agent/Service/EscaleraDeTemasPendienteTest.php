<?php

declare(strict_types=1);

namespace App\Tests\Agent\Service;

use App\Agent\Service\EscaleraDeTemas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La red de `escalar_al_equipo`: un paso de la guía que se acaba de dar y no es el último hay que
 * dejarlo probar antes de avisar a nadie; el último («avisa al equipo») deja pasar.
 *
 * Lo que lee de mensajes ya guardados necesita base y se prueba con el flujo real.
 */
final class EscaleraDeTemasPendienteTest extends TestCase
{
    private const string CONVERSACION = '01a1556b-bcd0-7d1a-b9ea-87ca8951c59b';

    #[Test]
    public function unPasoRecienDadoQueNoEsElUltimoFrenaElEscalado(): void
    {
        $escalera = new EscaleraDeTemas($this->createStub(EntityManagerInterface::class));
        $escalera->anotarServido('tema-puerta', 1, quedan: true);

        self::assertSame(
            ['tema' => 'tema-puerta', 'peldano' => 1, 'servidoAhora' => true],
            $escalera->pendienteReciente(self::CONVERSACION),
        );
    }

    #[Test]
    public function sinConversacionNoHayNadaQueFrenar(): void
    {
        $escalera = new EscaleraDeTemas($this->createStub(EntityManagerInterface::class));
        $escalera->anotarServido('tema-puerta', 0, quedan: true);

        self::assertNull($escalera->pendienteReciente(null));
    }
}
