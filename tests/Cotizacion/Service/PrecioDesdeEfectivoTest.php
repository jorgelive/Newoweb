<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Service\TourTarjetaResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El «desde» que se enseña: override escrito a mano o, si no hay, el calculado por pasajero del
 * financiero del cliente. Ver `TourTarjetaResolver::preciosDesdeEfectivos()` y
 * docs/Cotizaciones.md §6.b. El espejo TS (`preciosDesdeCalculados()`) sigue la misma regla.
 */
final class PrecioDesdeEfectivoTest extends TestCase
{
    /**
     * El snapshot tal como lo guarda el editor: una clase por tipo de pasajero, con el precio POR
     * PASAJERO en `resumenPorModo.normal`.
     *
     * @param list<array{0: string, 1: float, 2: float}> $clases [nombre, soles, dólares]
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $clases): array
    {
        return ['clasesPasajeros' => array_map(static fn (array $c): array => [
            'tipoPaxNombre' => $c[0],
            'resumenPorModo' => ['normal' => ['ventaSoles' => $c[1], 'ventaDolares' => $c[2]]],
        ], $clases)];
    }

    #[Test]
    public function sin_override_sale_el_calculado_redondeado_hacia_arriba(): void
    {
        // Punta Cana: US$ 1 598,95 por alumno → «desde US$ 1 599», nunca menos de lo real.
        $r = TourTarjetaResolver::preciosDesdeEfectivos([], $this->snapshot([['Cualquier Nacionalidad', 5346.9, 1598.95]]), 'USD', false);

        self::assertSame('calculado', $r['origen']);
        self::assertSame([['titulo' => [], 'moneda' => 'USD', 'valor' => '1599']], $r['precios']);
    }

    #[Test]
    public function la_moneda_del_tour_decide_que_columna_se_lee(): void
    {
        $r = TourTarjetaResolver::preciosDesdeEfectivos([], $this->snapshot([['General', 75.0, 22.05]]), 'PEN', false);

        self::assertSame('75', $r['precios'][0]['valor']);
        self::assertSame('PEN', $r['precios'][0]['moneda']);
    }

    #[Test]
    public function un_entero_exacto_no_sube_por_el_ruido_de_coma_flotante(): void
    {
        // 69.000000001 de una suma no es «70»: se redondea a céntimos antes del techo.
        $r = TourTarjetaResolver::preciosDesdeEfectivos([], $this->snapshot([['General', 69.000000001, 20.29]]), 'PEN', false);

        self::assertSame('69', $r['precios'][0]['valor']);
    }

    #[Test]
    public function con_varias_clases_van_de_menor_a_mayor_con_su_nombre_y_sin_las_gratis(): void
    {
        $r = TourTarjetaResolver::preciosDesdeEfectivos([], $this->snapshot([
            ['Extranjero', 250.0, 70.0],
            ['Infante', 0.0, 0.0],
            ['Peruano', 180.0, 50.0],
        ]), 'PEN', false);

        self::assertSame(['180', '250'], array_column($r['precios'], 'valor'), 'Una clase gratis anunciaría «desde S/ 0».');
        self::assertSame([['language' => 'es', 'content' => 'Peruano']], $r['precios'][0]['titulo']);
    }

    #[Test]
    public function el_override_manda_aunque_haya_calculado(): void
    {
        $manual = [['titulo' => [['language' => 'es', 'content' => 'Promo']], 'moneda' => 'PEN', 'valor' => '65']];
        $r = TourTarjetaResolver::preciosDesdeEfectivos($manual, $this->snapshot([['General', 75.0, 22.0]]), 'PEN', false);

        self::assertSame('manual', $r['origen']);
        self::assertSame('65', $r['precios'][0]['valor']);
    }

    #[Test]
    public function un_override_no_numerico_cuenta_como_vacio(): void
    {
        $r = TourTarjetaResolver::preciosDesdeEfectivos([['moneda' => 'PEN', 'valor' => '']], $this->snapshot([['General', 75.0, 22.0]]), 'PEN', false);

        self::assertSame('calculado', $r['origen']);
    }

    #[Test]
    public function precio_oculto_no_ensena_nada(): void
    {
        $r = TourTarjetaResolver::preciosDesdeEfectivos([['moneda' => 'PEN', 'valor' => '65']], $this->snapshot([['General', 75.0, 22.0]]), 'PEN', true);

        self::assertSame(['precios' => [], 'origen' => null], $r);
    }

    #[Test]
    public function sin_snapshot_no_hay_precio(): void
    {
        // Un tour nunca guardado desde el editor: no hay cálculo que leer, y no se inventa.
        self::assertSame(['precios' => [], 'origen' => null], TourTarjetaResolver::preciosDesdeEfectivos([], null, 'USD', false));
        self::assertSame(['precios' => [], 'origen' => null], TourTarjetaResolver::preciosDesdeEfectivos([], ['clasesPasajeros' => 'basura'], 'USD', false));
    }

    #[Test]
    public function la_base_de_grupo_solo_cuando_el_total_esta_oculto_y_es_mas_de_uno(): void
    {
        self::assertSame(60, TourTarjetaResolver::paxBaseGrupo(true, 60));
        self::assertNull(TourTarjetaResolver::paxBaseGrupo(true, 1));
        self::assertNull(TourTarjetaResolver::paxBaseGrupo(false, 60), 'Salida de grupo fijo: el total se vende, no hace falta decirlo.');
    }
}
