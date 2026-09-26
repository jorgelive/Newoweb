<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionPedido;
use App\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `resolverPorExpediente()` es lo único que de verdad decide algo aquí: las tres cosas que pone
 * a la vez, y por qué un cierre automático se distingue de uno manual sin una columna aparte.
 */
#[CoversClass(CotizacionPedido::class)]
final class CotizacionPedidoTest extends TestCase
{
    public function testNaceConversacionIdYTexto(): void
    {
        $pedido = new CotizacionPedido('01998f7e-1234-7abc-9def-000000000001', 'Valle Sagrado lunes 5 para 4');

        self::assertSame('01998f7e-1234-7abc-9def-000000000001', $pedido->getConversacionId());
        self::assertSame('Valle Sagrado lunes 5 para 4', $pedido->getTexto());
        self::assertTrue($pedido->isPendiente());
        self::assertNull($pedido->getFile());
        self::assertFalse($pedido->isCerradoAutomaticamente());
    }

    /**
     * El cierre automático pone las tres cosas a la vez: expediente, hora, y SIN autor humano. Un
     * `file` sin `efectuadaAt` seguiría en la lista de pendientes; un `efectuadaAt` sin `file` no
     * diría qué lo resolvió.
     */
    public function testResolverPorExpedienteCierraSinAutorHumano(): void
    {
        $pedido = new CotizacionPedido('01998f7e-1234-7abc-9def-000000000001', 'Valle Sagrado lunes 5 para 4');
        $file = (new \ReflectionClass(CotizacionFile::class))->newInstanceWithoutConstructor();

        $pedido->resolverPorExpediente($file);

        self::assertFalse($pedido->isPendiente());
        self::assertSame($file, $pedido->getFile());
        self::assertNotNull($pedido->getEfectuadaAt());
        self::assertNull($pedido->getEfectuadaPor());
        self::assertTrue(
            $pedido->isCerradoAutomaticamente(),
            'file puesto y sin efectuadaPor: nadie lo marcó a mano.'
        );
    }

    /** Marcado a mano (el procesador del panel pone efectuadaPor): ya NO cuenta como automático. */
    public function testMarcadoAManoNoEsAutomatico(): void
    {
        $pedido = new CotizacionPedido('01998f7e-1234-7abc-9def-000000000001', 'Valle Sagrado lunes 5 para 4');
        $usuario = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();

        $pedido->setEfectuadaAt(new \DateTimeImmutable())->setEfectuadaPor($usuario);

        self::assertFalse($pedido->isPendiente());
        self::assertFalse(
            $pedido->isCerradoAutomaticamente(),
            'Con efectuadaPor puesto, alguien lo marcó a mano: no fue el cierre automático.'
        );
    }

    public function testToStringEsElTexto(): void
    {
        $pedido = new CotizacionPedido('01998f7e-1234-7abc-9def-000000000001', 'Glaciar Quelccaya jueves 8');

        self::assertSame('Glaciar Quelccaya jueves 8', (string) $pedido);
    }
}
