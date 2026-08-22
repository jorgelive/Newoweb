<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Service\CadenaDeAlojamiento;
use App\Cotizacion\Service\Estancia;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué noche cubre qué estancia.
 *
 * Es la regla que convierte «el alojamiento del pasajero» en una dirección para el conductor. Si
 * se equivoca, el proveedor va a un hotel donde el pasajero ya no está — y la orden se lee
 * perfectamente correcta, porque el hotel existe y la fecha cuadra.
 *
 * Datos reales del itinerario de Nune (agosto de 2026), que es donde se verificó la cadena.
 */
final class CadenaDeAlojamientoTest extends TestCase
{
    private function cadena(): CadenaDeAlojamiento
    {
        return new CadenaDeAlojamiento([
            new Estancia(new DateTimeImmutable('2026-08-31'), new DateTimeImmutable('2026-09-04'), 'Sonesta Miraflores - Lima', 'Av. Alcanfores 329, Miraflores'),
            new Estancia(new DateTimeImmutable('2026-09-04'), new DateTimeImmutable('2026-09-06'), 'Hotel Terra - Cusco', 'Calle Unión 184, Cusco'),
            new Estancia(new DateTimeImmutable('2026-09-06'), new DateTimeImmutable('2026-09-08'), 'Hatun Inti Boutique - Mapi', 'Av. Imperio de los Incas 606'),
        ]);
    }

    #[Test]
    public function en_mitad_de_una_estancia_durmio_y_dormira_en_el_mismo_sitio(): void
    {
        $dia5 = new DateTimeImmutable('2026-09-05');

        self::assertSame('Hotel Terra - Cusco', $this->cadena()->dondeDurmio($dia5)?->hotel);
        self::assertSame('Hotel Terra - Cusco', $this->cadena()->dondeDormira($dia5)?->hotel);
    }

    #[Test]
    public function el_dia_del_cambio_SALE_de_uno_y_LLEGA_a_otro(): void
    {
        // ⚠️ El caso que justifica que sean dos preguntas y no una. El día 6 el pasajero
        // desayuna en Cusco y duerme en Machu Picchu: un traslado de ese día sale del hotel
        // viejo y llega al nuevo. Con una sola pregunta —«dónde está el día 6»— la respuesta
        // sería correcta la mitad de las veces, y la otra mitad mandaría al conductor al sitio
        // equivocado sin que nada lo delate.
        $dia6 = new DateTimeImmutable('2026-09-06');

        self::assertSame('Hotel Terra - Cusco', $this->cadena()->dondeDurmio($dia6)?->hotel);
        self::assertSame('Hatun Inti Boutique - Mapi', $this->cadena()->dondeDormira($dia6)?->hotel);
    }

    #[Test]
    public function el_dia_de_llegada_no_ha_dormido_en_ninguna_parte(): void
    {
        $llegada = new DateTimeImmutable('2026-08-31');

        self::assertNull($this->cadena()->dondeDurmio($llegada));
        self::assertSame('Sonesta Miraflores - Lima', $this->cadena()->dondeDormira($llegada)?->hotel);
    }

    #[Test]
    public function el_dia_de_salida_durmio_pero_ya_no_dormira(): void
    {
        $salida = new DateTimeImmutable('2026-09-08');

        self::assertSame('Hatun Inti Boutique - Mapi', $this->cadena()->dondeDurmio($salida)?->hotel);
        self::assertNull($this->cadena()->dondeDormira($salida));
    }

    #[Test]
    public function una_noche_SIN_estancia_devuelve_null_y_NO_el_hotel_anterior(): void
    {
        // ⚠️ El fallo caro que esto impide. En un trek se duerme en campamento y no hay estancia.
        // Caer al último hotel conocido daría una respuesta plausible —existe, y es donde estuvo
        // ayer— que manda al proveedor a cuatro horas de donde está el pasajero.
        $cadena = new CadenaDeAlojamiento([
            new Estancia(new DateTimeImmutable('2026-09-04'), new DateTimeImmutable('2026-09-06'), 'Hotel Terra - Cusco', 'Calle Unión 184'),
            new Estancia(new DateTimeImmutable('2026-09-09'), new DateTimeImmutable('2026-09-10'), 'Hotel Terra - Cusco', 'Calle Unión 184'),
        ]);

        // Días 6, 7 y 8: el trek. Sin estancia, y así tiene que decirse.
        foreach (['2026-09-07', '2026-09-08'] as $dia) {
            self::assertNull($cadena->dondeDormira(new DateTimeImmutable($dia)), $dia);
        }

        self::assertNull($cadena->dondeDurmio(new DateTimeImmutable('2026-09-08')));
    }

    #[Test]
    public function la_linea_de_la_orden_lleva_hotel_y_direccion(): void
    {
        $estancia = new Estancia(new DateTimeImmutable('2026-09-04'), new DateTimeImmutable('2026-09-06'), 'Hotel Terra - Cusco', 'Calle Unión 184, Cusco');

        self::assertSame('Hotel Terra - Cusco — Calle Unión 184, Cusco', $estancia->paraLaOrden());
        self::assertTrue($estancia->estaCompleta());
    }

    #[Test]
    public function sin_direccion_se_da_el_nombre_igual(): void
    {
        // «Natura Vive» no tiene dirección en su ficha. Callarse el nombre por eso dejaría el
        // renglón en blanco; con el nombre, el conductor al menos puede preguntar.
        $estancia = new Estancia(new DateTimeImmutable('2026-09-12'), new DateTimeImmutable('2026-09-13'), 'Natura Vive', null);

        self::assertSame('Natura Vive', $estancia->paraLaOrden());
        self::assertFalse($estancia->estaCompleta());
    }

    #[Test]
    public function dos_hoteles_la_misma_noche_se_pueden_DENUNCIAR(): void
    {
        // Un grupo repartido en dos hoteles es legítimo, y aquí no consta a cuál va cada
        // pasajero. `dondeDormira()` elige uno para no dejar el renglón en blanco, pero quien
        // llame tiene que poder ver que hay dos: elegir en silencio da una orden impecable que
        // manda a la mitad de la gente al sitio equivocado.
        $cadena = new CadenaDeAlojamiento([
            new Estancia(new DateTimeImmutable('2026-09-04'), new DateTimeImmutable('2026-09-06'), 'Hotel Terra - Cusco', 'Calle Unión 184'),
            new Estancia(new DateTimeImmutable('2026-09-04'), new DateTimeImmutable('2026-09-06'), 'Casa Andina - Cusco', 'Av. El Sol 500'),
        ]);

        $dia5 = new DateTimeImmutable('2026-09-05');

        self::assertCount(2, $cadena->cubrenLaNocheQueEmpieza($dia5));
        self::assertCount(2, $cadena->cubrenLaNocheQueTermina($dia5));
        self::assertNotNull($cadena->dondeDormira($dia5));
    }

    #[Test]
    public function una_noche_normal_tiene_exactamente_una_estancia(): void
    {
        self::assertCount(1, $this->cadena()->cubrenLaNocheQueEmpieza(new DateTimeImmutable('2026-09-05')));
        self::assertCount(0, $this->cadena()->cubrenLaNocheQueTermina(new DateTimeImmutable('2026-08-31')));
    }
}
