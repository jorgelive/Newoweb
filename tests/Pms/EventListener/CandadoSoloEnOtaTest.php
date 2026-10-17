<?php

declare(strict_types=1);

namespace App\Tests\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsReserva;
use App\Pms\EventListener\Queue\Beds24BookingsPushQueueListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El candado de datos (`datosLocked`) sólo calla los cambios del huésped en reservas de OTA.
 *
 * El pull lo cierra en cuanto llega un apellido o un teléfono, también con el eco de una reserva
 * DIRECTA. Cuando callaba siempre, corregir el nombre de una directa no llegaba a Beds24 ni a los
 * títulos `(M) …` de sus espejos. Ver docs/PmsBeds24ReservasSync.md §7.1.a.2.
 */
final class CandadoSoloEnOtaTest extends TestCase
{
    #[Test]
    public function en_una_directa_con_candado_el_nombre_si_sale(): void
    {
        $reserva = $this->reserva(ota: false);

        self::assertFalse(Beds24BookingsPushQueueListener::cambioSoloDeDatosDelCanal($reserva, ['apellidoCliente', 'telefono']));
    }

    #[Test]
    public function en_una_ota_con_candado_el_nombre_es_del_canal(): void
    {
        $reserva = $this->reserva(ota: true);

        self::assertTrue(Beds24BookingsPushQueueListener::cambioSoloDeDatosDelCanal($reserva, ['apellidoCliente', 'telefono']));
    }

    /** La nota es interna en Beds24 y el push la manda: si no saliera, el pull la pisaría. */
    #[Test]
    public function la_nota_sale_tambien_en_una_ota(): void
    {
        $reserva = $this->reserva(ota: true);

        self::assertFalse(Beds24BookingsPushQueueListener::cambioSoloDeDatosDelCanal($reserva, ['nota']));
    }

    #[Test]
    public function sin_candado_sale_todo(): void
    {
        $reserva = $this->reserva(ota: true)->setDatosLocked(false);

        self::assertFalse(Beds24BookingsPushQueueListener::cambioSoloDeDatosDelCanal($reserva, ['apellidoCliente']));
    }

    private function reserva(bool $ota): PmsReserva
    {
        $reserva = (new PmsReserva())->setDatosLocked(true);
        $reserva->addEventosCalendario((new PmsEventoCalendario())->setIsOta($ota));

        return $reserva;
    }
}
