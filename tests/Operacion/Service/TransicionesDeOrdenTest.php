<?php

declare(strict_types=1);

namespace App\Tests\Operacion\Service;

use App\Operacion\Enum\EstadoOrdenServicioEnum as E;
use App\Operacion\Service\OperacionOrdenEmision;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Qué transiciones acepta el backend — y por tanto qué botones puede ofrecer el panel.
 *
 * ⚠️ El panel pinta las acciones con `accionesDe()` en `OperacionView.vue`, que es un **espejo**
 * de estas reglas. Si aquí se admite una transición nueva y allí no se añade, el botón no
 * existe; si allí se ofrece una que aquí se rechaza, el operador se come un 422 después de
 * confirmar. Estos tests fijan el contrato de los dos lados.
 */
final class TransicionesDeOrdenTest extends TestCase
{
    #[Test]
    public function una_anulada_no_vuelve_de_ninguna_manera(): void
    {
        // Por eso el panel no le ofrece NINGÚN botón de estado a una cancelada.
        foreach ([E::BORRADOR, E::EMITIDA, E::CONFIRMADA, E::COMPLETADA] as $destino) {
            try {
                $this->emision()->validarTransicion(E::CANCELADA, $destino);
                self::fail("dejó pasar cancelada → {$destino->value}");
            } catch (DomainException $e) {
                self::assertStringContainsString('no vuelve atrás', $e->getMessage());
            }
        }
    }

    #[Test]
    public function una_emitida_no_regresa_a_borrador(): void
    {
        // «El proveedor ya la tiene». Es la misma razón por la que tampoco se puede BORRAR
        // —ver `OperacionOrdenBorradoListener`—, y por la que el botón de reemitir existe.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/no vuelve a borrador/');

        $this->emision()->validarTransicion(E::EMITIDA, E::BORRADOR);
    }

    #[Test]
    public function las_que_el_panel_OFRECE_son_todas_validas(): void
    {
        // 🪞 Espejo literal de `accionesDe()`. Si el panel ofrece algo que aquí falla, el
        // operador confirma y se come un 422.
        $ofrece = [
            'borrador'   => [E::EMITIDA],
            'emitida'    => [E::CONFIRMADA, E::CANCELADA],
            'confirmada' => [E::COMPLETADA, E::CANCELADA],
            'completada' => [E::CANCELADA],
            'cancelada'  => [],
        ];

        foreach ($ofrece as $desde => $destinos) {
            foreach ($destinos as $destino) {
                $this->emision()->validarTransicion(E::from($desde), $destino);
            }
        }

        self::assertTrue(true, 'ninguna de las transiciones que ofrece el panel es rechazada');
    }

    private function emision(): OperacionOrdenEmision
    {
        // `validarTransicion()` es pura: no toca ninguna de sus dependencias.
        return new ReflectionClass(OperacionOrdenEmision::class)->newInstanceWithoutConstructor();
    }
}
