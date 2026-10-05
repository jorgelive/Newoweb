<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Entity\CotizacionCotservicio;
use App\Cotizacion\Entity\CotizacionCottarifa;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cambiar los pasajeros de una copia **y que el precio cambie con ellos**.
 *
 * ⚠️ `numPax` no interviene en el cálculo financiero: éste multiplica por la `cantidad` de cada
 * tarifa. Una copia «para 60» con las tarifas en 100 sigue costando lo de 100, sin que nada se
 * queje y con un total que se lee perfectamente plausible.
 */
final class CotizacionAjustarPaxTest extends TestCase
{
    /** @param list<array{int, bool}> $tarifas  [cantidad, ¿es grupal?] */
    private function cotizacion(int $pax, array $tarifas): Cotizacion
    {
        $cotizacion = new Cotizacion();
        $cotizacion->setNumPax($pax);

        $servicio = new CotizacionCotservicio();
        $componente = new CotizacionCotcomponente();

        foreach ($tarifas as [$cantidad, $grupal]) {
            $tarifa = new CotizacionCottarifa();
            $tarifa->setCantidad($cantidad);
            $tarifa->setCalculoSnapshot($grupal ? 'grupal' : 'individual');
            $componente->addCottarifa($tarifa);
        }

        $servicio->addCotcomponente($componente);
        $cotizacion->addCotservicio($servicio);

        return $cotizacion;
    }

    /** @return list<int> */
    private function cantidades(Cotizacion $c): array
    {
        $salida = [];

        foreach ($c->getCotservicios() as $s) {
            foreach ($s->getCotcomponentes() as $comp) {
                foreach ($comp->getCottarifas() as $t) {
                    $salida[] = $t->getCantidad();
                }
            }
        }

        return $salida;
    }

    #[Test]
    public function las_tarifas_que_cubren_a_todos_siguen_al_grupo(): void
    {
        // El caso real: la promoción de La Salle, 35 tarifas por persona a 100.
        $c = $this->cotizacion(100, [[100, false], [100, false], [100, false]]);

        self::assertSame(['ajustadas' => 3, 'respetadas' => 0], $c->ajustarPax(60));
        self::assertSame(60, $c->getNumPax());
        self::assertSame([60, 60, 60], $this->cantidades($c));
    }

    #[Test]
    public function una_tarifa_GRUPAL_no_se_multiplica(): void
    {
        // Su precio es por grupo, no por cabeza. Que el grupo sea menor es una renegociación con
        // el proveedor, no una multiplicación.
        $c = $this->cotizacion(100, [[100, false], [1, true]]);

        self::assertSame(['ajustadas' => 1, 'respetadas' => 0], $c->ajustarPax(60));
        self::assertSame([60, 1], $this->cantidades($c));
    }

    #[Test]
    public function un_reparto_deliberado_se_RESPETA_y_se_cuenta(): void
    {
        // ⚠️ «2 Peruano + 1 Cusqueño + 1 No necesario» reparte 4 pax entre tres tarifas.
        // Reescribir cada línea al total multiplicaría el grupo por el número de líneas, y el
        // error saldría como un precio alto, no como un fallo. Eso lo reparte una persona.
        $c = $this->cotizacion(4, [[2, false], [1, false], [1, false]]);

        self::assertSame(['ajustadas' => 0, 'respetadas' => 3], $c->ajustarPax(10));
        self::assertSame([2, 1, 1], $this->cantidades($c), 'Ninguna cubría al grupo entero.');
        self::assertSame(10, $c->getNumPax(), 'El número sí cambia: lo que no se toca es el reparto.');
    }

    #[Test]
    public function mezcla_de_los_tres_casos(): void
    {
        $c = $this->cotizacion(100, [[100, false], [1, true], [60, false], [100, false]]);

        self::assertSame(['ajustadas' => 2, 'respetadas' => 1], $c->ajustarPax(60));
        self::assertSame([60, 1, 60, 60], $this->cantidades($c));
    }
}
