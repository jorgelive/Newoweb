<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Enum\PresentacionPrecioEnum as P;
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

    /**
     * El financiero de un traslado privado: un Sedán de S/ 36 repartido entre 4 → S/ 9 por persona.
     *
     * @return array<string, mixed>
     */
    private function traslado(): array
    {
        return $this->snapshot([['Cualquier Nacionalidad', 9.0, 2.61]])
            + ['resumenGeneral' => ['incluido' => ['ventaSoles' => 36, 'ventaDolares' => 10.44]]];
    }

    #[Test]
    public function con_los_totales_a_la_vista_la_tarjeta_da_el_total_del_grupo(): void
    {
        $precios = TourTarjetaResolver::preciosDesdeEfectivos([], $this->traslado(), 'USD', false);

        self::assertSame(
            ['moneda' => 'USD', 'valor' => '11', 'pax' => 4],
            TourTarjetaResolver::totalDelGrupo($precios['precios'], $precios['origen'], $this->traslado(), 'USD', P::TOTAL_GRUPO, 4),
            'US$ 10,44 se anuncia «11», hacia arriba como el desde: nunca menos de lo real.',
        );
    }

    #[Test]
    public function el_total_no_contradice_al_por_persona_por_unos_centimos(): void
    {
        // Valle Sagrado real: S/ 69 por persona, base de 2, y el total guardado como 138,01.
        $valle = $this->snapshot([['General', 69.0, 20.29]])
            + ['resumenGeneral' => ['incluido' => ['ventaSoles' => 138.01, 'ventaDolares' => 40.58]]];
        $precios = TourTarjetaResolver::preciosDesdeEfectivos([], $valle, 'PEN', false);

        self::assertSame('138', TourTarjetaResolver::totalDelGrupo($precios['precios'], $precios['origen'], $valle, 'PEN', P::TOTAL_GRUPO, 2)['valor'] ?? null, '«S/ 139 · S/ 69 por persona» no cuadra delante del cliente.');
    }

    #[Test]
    public function por_persona_con_la_base_no_da_total_sino_la_base(): void
    {
        $precios = TourTarjetaResolver::preciosDesdeEfectivos([], $this->traslado(), 'USD', false);

        self::assertNull(TourTarjetaResolver::totalDelGrupo($precios['precios'], $precios['origen'], $this->traslado(), 'USD', P::POR_PERSONA_BASE, 4));
        self::assertSame(4, TourTarjetaResolver::paxBaseGrupo(P::POR_PERSONA_BASE, 4, false));
    }

    #[Test]
    public function el_total_no_se_inventa_cuando_no_es_inequivoco(): void
    {
        $t = $this->traslado();
        $precios = TourTarjetaResolver::preciosDesdeEfectivos([], $t, 'USD', false);

        self::assertNull(TourTarjetaResolver::totalDelGrupo($precios['precios'], $precios['origen'], $t, 'USD', P::TOTAL_GRUPO, 1), 'Base de uno: el total ES el precio por persona.');
        self::assertNull(TourTarjetaResolver::totalDelGrupo($precios['precios'], 'manual', $t, 'USD', P::TOTAL_GRUPO, 4), 'Un precio escrito a mano es un override: manda tal cual.');

        $dosClases = $this->snapshot([['Peruano', 180.0, 50.0], ['Extranjero', 250.0, 70.0]])
            + ['resumenGeneral' => ['incluido' => ['ventaSoles' => 430, 'ventaDolares' => 120]]];
        $varios = TourTarjetaResolver::preciosDesdeEfectivos([], $dosClases, 'USD', false);
        self::assertNull(TourTarjetaResolver::totalDelGrupo($varios['precios'], $varios['origen'], $dosClases, 'USD', P::TOTAL_GRUPO, 2), 'Con varias clases el total depende de la mezcla.');

        self::assertNull(TourTarjetaResolver::totalDelGrupo($precios['precios'], $precios['origen'], $this->snapshot([['General', 9.0, 2.61]]), 'USD', P::TOTAL_GRUPO, 4), 'Sin resumen guardado no hay total que leer.');
    }

    /**
     * El mapa de casos del 14/10/2026: cuándo la tarjeta añade «calculado para N».
     */
    #[Test]
    public function la_base_la_decide_la_presentacion_y_no_va_si_ya_sale_el_total(): void
    {
        self::assertSame(60, TourTarjetaResolver::paxBaseGrupo(P::POR_PERSONA_BASE, 60, false), 'Punta Cana.');
        self::assertNull(TourTarjetaResolver::paxBaseGrupo(P::POR_PERSONA, 2, false), 'Machu Picchu con varios perfiles: quien la quita, la quita.');
        self::assertNull(TourTarjetaResolver::paxBaseGrupo(P::TOTAL_GRUPO, 4, true), 'Si ya sale «total para 4», la base sobra.');
        self::assertSame(2, TourTarjetaResolver::paxBaseGrupo(P::TOTAL_GRUPO, 2, false), 'Total pedido pero imposible (varios perfiles, override): queda la base.');
        self::assertNull(TourTarjetaResolver::paxBaseGrupo(P::POR_PERSONA_BASE, 1, false), 'Base de uno: no hay grupo del que hablar.');
        self::assertNull(TourTarjetaResolver::paxBaseGrupo(P::SIN_PRECIO, 4, false));
    }

    #[Test]
    public function los_booleanos_de_siempre_salen_derivados_de_la_presentacion(): void
    {
        self::assertTrue(P::SIN_PRECIO->ocultaPrecio());
        self::assertTrue(P::SIN_PRECIO->ocultaTotales());
        self::assertFalse(P::TOTAL_GRUPO->ocultaTotales(), 'La guía enseña «Precio total del viaje».');
        self::assertTrue(P::POR_PERSONA_BASE->ocultaTotales());
        self::assertTrue(P::POR_PERSONA->ocultaTotales());
        self::assertFalse(P::POR_PERSONA->ocultaPrecio());
    }
}
