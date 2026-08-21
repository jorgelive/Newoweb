<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsReserva;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ¿Hay una plataforma entre el huésped y nosotros?
 *
 * De esta pregunta cuelgan cuatro decisiones —si se ofrece el canal del channel manager, si se
 * permite mandar por su API, si el correo del asunto es un alias exclusivo y qué plantillas
 * encajan— y estuvo escrita **tres veces como lista de códigos**, dos de ellas sin normalizar.
 * Una reserva podía ser directa para un filtro y de plataforma para el otro.
 */
final class ReservaEsDePlataformaTest extends TestCase
{
    #[Test]
    public function manda_el_flag_del_canal_y_no_su_identificador(): void
    {
        // El caso que la lista de códigos no cubría: un canal nuevo dado de alta en el panel.
        // Su `id` no está en ninguna lista escrita a mano, pero SÍ dice de sí mismo que es
        // venta directa — y antes se le trataba como plataforma sin un solo error.
        $canal = new PmsChannel();
        $canal->setId('feria-artesanal')->setEsDirecto(true);

        self::assertFalse($this->reservaCon($canal)->esDePlataforma());
    }

    #[Test]
    public function un_canal_externo_es_plataforma(): void
    {
        $canal = new PmsChannel();
        $canal->setId(PmsChannel::CODIGO_BOOKING)->setEsDirecto(false);

        self::assertTrue($this->reservaCon($canal)->esDePlataforma());
    }

    #[Test]
    public function sin_canal_la_reserva_es_NUESTRA(): void
    {
        // Una reserva sin canal es una que se cargó a mano. Tratarla como de plataforma
        // encendería el canal del channel manager sobre una estancia que no existe allí.
        self::assertFalse($this->reservaCon(null)->esDePlataforma());
    }

    private function reservaCon(?PmsChannel $canal): PmsReserva
    {
        $reserva = new PmsReserva();

        return $canal !== null ? $reserva->setChannel($canal) : $reserva;
    }
}
