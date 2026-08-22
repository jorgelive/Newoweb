<?php

declare(strict_types=1);

namespace App\Tests\Travel\Entity;

use App\Travel\Entity\TravelOrganizacion;
use App\Travel\Entity\TravelPunto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se convierte un punto en la línea que lee el proveedor.
 *
 * Lo que se prueba aquí es la única salida del punto hacia fuera del sistema: si esta cadena
 * sale mal, el conductor va al sitio equivocado y no hay nada que lo delate antes.
 */
final class TravelPuntoTest extends TestCase
{
    #[Test]
    public function la_direccion_propia_MANDA_sobre_la_del_proveedor(): void
    {
        // Alguien se molestó en escribirla, y sólo se hace cuando la del proveedor NO sirve:
        // la puerta de servicio, otra sede. Invertir la precedencia anularía ese trabajo.
        $organizacion = new TravelOrganizacion();
        $organizacion->setDireccion('Av. El Sol 123, Cusco');

        $punto = new TravelPunto();
        $punto->setNombre('Hotel Terra');
        $punto->setOrganizacion($organizacion);
        $punto->setDireccion('Puerta de servicio, Calle Saphi 45');

        self::assertSame('Puerta de servicio, Calle Saphi 45', $punto->direccionEfectiva());
    }

    #[Test]
    public function sin_direccion_propia_hereda_la_del_proveedor(): void
    {
        $organizacion = new TravelOrganizacion();
        $organizacion->setDireccion('Av. El Sol 123, Cusco');

        $punto = new TravelPunto();
        $punto->setNombre('Hotel Terra');
        $punto->setOrganizacion($organizacion);

        self::assertSame('Av. El Sol 123, Cusco', $punto->direccionEfectiva());
    }

    #[Test]
    public function una_direccion_en_blanco_no_cuenta_como_direccion(): void
    {
        // Un campo con espacios pasa cualquier `!== null` y luego sale como una línea vacía en
        // la orden. Se trata como ausente para que `estaCompleto()` lo denuncie.
        $punto = new TravelPunto();
        $punto->setNombre('Plaza de Armas de Cusco');
        $punto->setDireccion('   ');

        self::assertNull($punto->direccionEfectiva());
        self::assertFalse($punto->estaCompleto());
    }

    #[Test]
    public function la_linea_de_la_orden_lleva_nombre_direccion_y_referencia(): void
    {
        $punto = new TravelPunto();
        $punto->setNombre('Estación de Ollantaytambo');
        $punto->setDireccion('Av. Ferrocarril s/n, Ollantaytambo');
        $punto->setReferencia('el bus no entra, se caminan 50 m');

        self::assertSame(
            'Estación de Ollantaytambo — Av. Ferrocarril s/n, Ollantaytambo (el bus no entra, se caminan 50 m)',
            $punto->paraLaOrden()
        );
    }

    #[Test]
    public function sin_referencia_no_deja_los_parentesis_vacios(): void
    {
        $punto = new TravelPunto();
        $punto->setNombre('Aeropuerto de Cusco');
        $punto->setDireccion('Av. Velasco Astete s/n');

        self::assertSame('Aeropuerto de Cusco — Av. Velasco Astete s/n', $punto->paraLaOrden());
    }

    #[Test]
    public function un_punto_sin_direccion_no_esta_completo(): void
    {
        // Es el aviso que evita mandar «te recogemos en la Plaza de Armas» y que el proveedor
        // tenga que llamar para preguntar en qué esquina.
        $punto = new TravelPunto();
        $punto->setNombre('Plaza de Armas de Cusco');

        self::assertFalse($punto->estaCompleto());
        self::assertSame('Plaza de Armas de Cusco', $punto->paraLaOrden());
    }
}
