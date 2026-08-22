<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Enum\ComponenteEstadoEnum;
use App\Travel\Enum\ComponenteModoEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué componentes siguen contando y cuáles son historia.
 *
 * Aquí no se borra nada, así que todo lo que recorra los componentes tiene que preguntar esto. Se
 * equivoca en un sentido y se compra lo cancelado; se equivoca en el otro y el conductor se queda
 * sin la dirección del hotel que el pasajero reservó por su cuenta.
 */
final class CotizacionCotcomponenteVivoTest extends TestCase
{
    private function comp(ComponenteEstadoEnum $estado, ComponenteModoEnum $modo): CotizacionCotcomponente
    {
        $c = new CotizacionCotcomponente();
        $c->setEstado($estado);
        $c->setModo($modo);

        return $c;
    }

    #[Test]
    public function lo_normal_esta_vivo(): void
    {
        self::assertTrue($this->comp(ComponenteEstadoEnum::ACTIVO, ComponenteModoEnum::INCLUIDO)->estaVivo());
    }

    #[Test]
    public function no_incluido_SIGUE_VIVO_y_es_la_distincion_que_se_pierde(): void
    {
        // ⚠️ Es el hotel que el pasajero reservó por su cuenta: no se le compra a nadie, pero es
        // donde hay que recogerlo. Meterlo en el mismo saco que lo cancelado —el error fácil al
        // filtrar «por modo»— deja al transportista sin dirección.
        self::assertTrue($this->comp(ComponenteEstadoEnum::ACTIVO, ComponenteModoEnum::NO_INCLUIDO)->estaVivo());
        self::assertTrue($this->comp(ComponenteEstadoEnum::ACTIVO, ComponenteModoEnum::CORTESIA)->estaVivo());
    }

    #[Test]
    public function cancelado_y_reemplazado_estan_muertos(): void
    {
        // Cambiar el hotel A por el B para las mismas noches deja la fila de A viva y marcada.
        // Sin este corte, la cadena de alojamiento tendría LAS DOS sobre la misma noche y la
        // orden podría salir con el hotel viejo: nombre y dirección reales, fechas que cuadran,
        // imposible de detectar leyéndola.
        self::assertFalse($this->comp(ComponenteEstadoEnum::CANCELADO, ComponenteModoEnum::INCLUIDO)->estaVivo());
        self::assertFalse($this->comp(ComponenteEstadoEnum::ACTIVO, ComponenteModoEnum::REEMPLAZADO)->estaVivo());
    }
}
