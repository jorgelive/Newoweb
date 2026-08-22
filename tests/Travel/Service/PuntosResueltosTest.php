<?php

declare(strict_types=1);

namespace App\Tests\Travel\Service;

use App\Travel\Entity\TravelPunto;
use App\Travel\Enum\PuntoModoEnum;
use App\Travel\Service\PuntosResueltos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo se considera que una orden ya sabe dónde recoge y dónde deja.
 *
 * La regla que se prueba aquí decide qué sale en el informe de lo que falta. Un criterio
 * demasiado estricto llena la lista de avisos que nadie puede resolver —y una lista así se deja
 * de mirar—; demasiado laxo, y la orden sale sin sitio de recojo sin que nadie se entere.
 */
final class PuntosResueltosTest extends TestCase
{
    private function punto(bool $completo): TravelPunto
    {
        $punto = new TravelPunto();
        $punto->setNombre('Estación de Ollantaytambo');

        if ($completo) {
            $punto->setDireccion('Av. Ferrocarril s/n');
        }

        return $punto;
    }

    #[Test]
    public function un_servicio_que_no_recoge_a_nadie_esta_completo_por_definicion(): void
    {
        // Un ticket o una comida no tienen extremos. Marcarlos como incompletos metería en la
        // lista de pendientes la mitad del catálogo, y nada de eso se puede rellenar.
        $r = PuntosResueltos::noAplica();

        self::assertTrue($r->estaCompleto());
        self::assertSame([], $r->faltantes());
    }

    #[Test]
    public function el_hotel_del_pasajero_CUENTA_como_declarado(): void
    {
        // ⚠️ El catálogo ya dijo todo lo que podía decir: cuál hotel lo pone la reserva. Si esto
        // contara como hueco, TODOS los pool saldrían en la lista de pendientes para siempre.
        $r = new PuntosResueltos(
            PuntoModoEnum::ALOJAMIENTO, null,
            PuntoModoEnum::ALOJAMIENTO, null,
            aplica: true, tieneFin: true,
        );

        self::assertTrue($r->estaCompleto());
        self::assertSame([], $r->faltantes());
    }

    #[Test]
    public function sin_declarar_es_lo_unico_que_de_verdad_falta(): void
    {
        $r = new PuntosResueltos(
            PuntoModoEnum::SIN_DEFINIR, null,
            PuntoModoEnum::SIN_DEFINIR, null,
            aplica: true, tieneFin: true,
        );

        self::assertFalse($r->estaCompleto());
        self::assertSame(['inicio sin declarar', 'fin sin declarar'], $r->faltantes());
    }

    #[Test]
    public function un_punto_fijo_SIN_direccion_tampoco_vale(): void
    {
        // Es el caso traicionero: el extremo está declarado, así que a ojo parece resuelto, y
        // lo que llega al proveedor es un nombre sin sitio al que ir.
        $r = new PuntosResueltos(
            PuntoModoEnum::FIJO, $this->punto(completo: false),
            PuntoModoEnum::FIJO, $this->punto(completo: true),
            aplica: true, tieneFin: true,
        );

        self::assertFalse($r->estaCompleto());
        self::assertSame(['punto de inicio incompleto'], $r->faltantes());
    }

    #[Test]
    public function el_guiado_no_arrastra_su_fin_sin_declarar(): void
    {
        // El guía se presenta en un punto y ahí acaba su parte: exigirle un punto de entrega
        // llenaría la lista con un hueco que no existe.
        $r = new PuntosResueltos(
            PuntoModoEnum::FIJO, $this->punto(completo: true),
            PuntoModoEnum::SIN_DEFINIR, null,
            aplica: true, tieneFin: false,
        );

        self::assertTrue($r->estaCompleto());
        self::assertSame([], $r->faltantes());
    }

    #[Test]
    public function un_punto_fijo_sin_punto_elegido_no_pasa(): void
    {
        $r = new PuntosResueltos(
            PuntoModoEnum::FIJO, null,
            PuntoModoEnum::FIJO, null,
            aplica: true, tieneFin: true,
        );

        self::assertFalse($r->estaCompleto());
        self::assertCount(2, $r->faltantes());
    }
}
