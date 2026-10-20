<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller\Cotizacion;

use App\Api\Controller\Cotizacion\ListaParaProveedorController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Los avisos van en una cabecera, y una cabecera que pasa del búfer de nginx es un 502 sin fichero.
 */
final class ListaParaProveedorAvisosTest extends TestCase
{
    /** @param list<string> $avisos */
    private function cabecera(array $avisos): string
    {
        $metodo = new ReflectionMethod(ListaParaProveedorController::class, 'avisosParaCabecera');

        $valor = $metodo->invoke(null, $avisos);
        self::assertIsString($valor);

        return $valor;
    }

    #[Test]
    public function pocosAvisosVanEnteros(): void
    {
        $avisos = ['Ana Pérez: falta el sexo.', 'Luis Quispe: falta el país.'];

        self::assertSame($avisos, json_decode(rawurldecode($this->cabecera($avisos)), true));
    }

    #[Test]
    public function unGrupoGrandeSeAcotaYDiceCuantosFaltan(): void
    {
        $avisos = array_map(static fn (int $i): string => "Pasajera número {$i} con tildes áéíóú: falta el sexo y el país.", range(1, 133));

        $cabecera = $this->cabecera($avisos);
        $lista = json_decode(rawurldecode($cabecera), true);

        self::assertLessThanOrEqual(2000, \strlen($cabecera));
        self::assertIsArray($lista);
        self::assertStringContainsString('aviso(s) más', (string) end($lista));
        self::assertGreaterThan(1, \count($lista));
    }
}
