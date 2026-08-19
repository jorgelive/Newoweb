<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El asunto del que habla quien escribe, y su identificador.
 *
 * Lo que se protege aquí es que el id sea **estable y sin colisiones**: es lo único con lo que
 * el modelo señala de qué está hablando, y dos frentes con el mismo id significan que elegir
 * uno acaba resolviendo el otro.
 */
final class FrenteTest extends TestCase
{
    private function venta(string $negocio = 'hotelero'): Frente
    {
        return new Frente($negocio, MomentoDeFrente::Venta, 'Reservar alojamiento');
    }

    #[Test]
    public function la_puerta_de_venta_no_tiene_entidad(): void
    {
        self::assertTrue($this->venta()->esVentaSintetica());
    }

    /**
     * El guard que cierra una colisión real: un frente en venta con `entidadTipo` pero sin
     * `entidadId` pasaba por puerta sintética Y generaba el mismo hash que la puerta de verdad
     * del mismo negocio, porque no había nada en el id que los distinguiera.
     */
    #[Test]
    public function un_frente_con_tipo_pero_sin_id_no_se_puede_construir(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Frente('hotelero', MomentoDeFrente::Venta, 'Rara', entidadTipo: 'pms_reserva');
    }

    #[Test]
    public function un_frente_con_id_pero_sin_tipo_tampoco(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Frente('hotelero', MomentoDeFrente::Venta, 'Rara', entidadId: 'abc');
    }

    /** Mismo asunto, mismo id entre turnos: de eso depende que una aclaración de ayer sirva hoy. */
    #[Test]
    public function el_id_es_estable_para_el_mismo_asunto(): void
    {
        $uno = new Frente('hotelero', MomentoDeFrente::Operacion, 'Tu reserva', 'pms_reserva', 'abc');
        $otro = new Frente('hotelero', MomentoDeFrente::Operacion, 'Otra etiqueta', 'pms_reserva', 'abc');

        // La etiqueta NO entra en el id: cambiar el texto no puede cambiar de qué se hablaba.
        self::assertSame($uno->id(), $otro->id());
    }

    /** Dos entidades distintas del mismo negocio nunca comparten id. */
    #[Test]
    public function el_id_distingue_entidades_y_tipos(): void
    {
        $reserva = new Frente('hotelero', MomentoDeFrente::Operacion, 'A', 'pms_reserva', 'abc');
        $otra = new Frente('hotelero', MomentoDeFrente::Operacion, 'B', 'pms_reserva', 'xyz');
        // Mismo id textual, tabla distinta: sin `entidadTipo` en el hash, colisionaban.
        $otroTipo = new Frente('hotelero', MomentoDeFrente::Operacion, 'C', 'cotizacion_file', 'abc');

        self::assertNotSame($reserva->id(), $otra->id());
        self::assertNotSame($reserva->id(), $otroTipo->id());
    }

    /** Y ninguno choca con la puerta de venta de su propio negocio. */
    #[Test]
    public function ningun_asunto_colisiona_con_la_puerta_de_venta(): void
    {
        $consulta = new Frente('hotelero', MomentoDeFrente::Venta, 'Tu consulta', 'pms_reserva', 'abc');

        self::assertNotSame($this->venta()->id(), $consulta->id());
    }

    /**
     * Marcar el defecto no puede perder campos por el camino: se hacía reconstruyendo el objeto
     * a mano, y el día que `Frente` ganara una propiedad se habría perdido en silencio sólo en
     * el frente por defecto.
     */
    #[Test]
    public function marcar_por_defecto_conserva_todo_lo_demas(): void
    {
        $original = new Frente('hotelero', MomentoDeFrente::Operacion, 'Tu reserva', 'pms_reserva', 'abc');
        $marcado = $original->marcadoPorDefecto();

        self::assertTrue($marcado->porDefecto);
        self::assertFalse($original->porDefecto, 'el original no se muta');
        self::assertSame($original->id(), $marcado->id());
        self::assertSame($original->etiqueta, $marcado->etiqueta);
        self::assertSame($original->entidadTipo, $marcado->entidadTipo);
        self::assertSame($original->entidadId, $marcado->entidadId);
    }

    #[Test]
    public function la_linea_del_prompt_lleva_id_negocio_y_etiqueta(): void
    {
        $linea = $this->venta()->marcadoPorDefecto()->comoLinea();

        self::assertStringContainsString('hotelero', $linea);
        self::assertStringContainsString('Reservar alojamiento', $linea);
        self::assertStringContainsString('(por defecto)', $linea);
    }
}
