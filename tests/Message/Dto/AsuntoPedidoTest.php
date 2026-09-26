<?php

declare(strict_types=1);

namespace App\Tests\Message\Dto;

use App\Message\Dto\AsuntoPedido;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** El asunto del cuerpo de «abrir hilo» y «cambiar titular»: la misma lectura que el cast de antes. */
#[CoversClass(AsuntoPedido::class)]
final class AsuntoPedidoTest extends TestCase
{
    #[Test]
    public function recorta_los_dos_campos(): void
    {
        $asunto = AsuntoPedido::fromArray(['contextType' => ' pms_reserva ', 'contextId' => ' 0192-abc ']);

        self::assertSame('pms_reserva', $asunto->tipo);
        self::assertSame('0192-abc', $asunto->id);
        self::assertFalse($asunto->estaIncompleto());
    }

    #[Test]
    public function falta_uno_o_llega_de_otro_tipo_y_esta_incompleto(): void
    {
        self::assertTrue(AsuntoPedido::fromArray(['contextType' => 'pms_reserva'])->estaIncompleto());
        self::assertTrue(AsuntoPedido::fromArray(['contextType' => 'pms_reserva', 'contextId' => '   '])->estaIncompleto());
        // Un array ya no se convierte en la palabra «Array», que habría buscado un asunto con ese id.
        self::assertTrue(AsuntoPedido::fromArray(['contextType' => 'pms_reserva', 'contextId' => ['x']])->estaIncompleto());
        self::assertTrue(AsuntoPedido::fromArray([])->estaIncompleto());
    }

    /** Un id numérico en el JSON se lee como texto, igual que con el `(string)` de antes. */
    #[Test]
    public function un_id_numerico_pasa_a_texto(): void
    {
        self::assertSame('42', AsuntoPedido::fromArray(['contextType' => 'cotizacion_file', 'contextId' => 42])->id);
    }
}
