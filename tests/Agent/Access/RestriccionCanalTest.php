<?php

declare(strict_types=1);

namespace App\Tests\Agent\Access;

use App\Agent\Access\RestriccionCanal;
use App\Message\Contract\CategoriaConocimiento;
use App\Message\Contract\VinculoComercial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La RESTA por canal: qué no sale por este tubo, sea quien sea quien pregunte.
 *
 * Viene de una fuga real: a una interesada que llegó por Airbnb sin confirmar se le dio la
 * dirección exacta del alojamiento. El sistema no falló —la ubicación está marcada como
 * pública y entregarla era lo correcto según el modelo de entonces—; lo que faltaba era poder
 * decir que ese dato, siendo público en la web, no puede salir por el canal de una OTA antes
 * de que la reserva se cierre.
 *
 * Lo que estos tests fijan es que sigue siendo una resta ORTOGONAL y no un escalón más: la
 * interesada merece MÁS que el escaparate (camas, distribución, check-in) y MENOS en una
 * dimensión concreta. Si alguien lo convierte en un peldaño, `permite_lo_general` cae.
 */
final class RestriccionCanalTest extends TestCase
{
    public static function canales(): iterable
    {
        yield 'airbnb sin confirmar restringe' => ['airbnb', VinculoComercial::Interesado, true];
        yield 'booking sin confirmar restringe' => ['booking', VinculoComercial::Interesado, true];
        yield 'airbnb ya confirmado NO restringe' => ['airbnb', VinculoComercial::Cliente, false];
        yield 'canal propio nunca restringe' => ['directo', VinculoComercial::Interesado, false];
        yield 'sin origen conocido no restringe' => [null, VinculoComercial::Interesado, false];
        yield 'cancelada de OTA sigue restringida' => ['airbnb', VinculoComercial::Terminado, true];
    }

    #[Test]
    #[DataProvider('canales')]
    public function la_restriccion_sale_del_origen_y_del_vinculo(
        ?string $origen,
        VinculoComercial $vinculo,
        bool $esperaRestriccion
    ): void {
        $restriccion = RestriccionCanal::deOrigenYVinculo($origen, $vinculo);

        self::assertSame($esperaRestriccion, $restriccion->restringe());
    }

    /**
     * Una vez confirmada, la plataforma sí permite dar dirección y contacto — que es justo lo
     * que el huésped necesita para llegar. Restringir ahí sería el error contrario.
     */
    #[Test]
    public function confirmar_la_reserva_levanta_la_restriccion(): void
    {
        $antes = RestriccionCanal::deOrigenYVinculo('airbnb', VinculoComercial::Interesado);
        $despues = RestriccionCanal::deOrigenYVinculo('airbnb', VinculoComercial::Cliente);

        self::assertSame(RestriccionCanal::OtaPreReserva, $antes);
        self::assertSame(RestriccionCanal::Ninguna, $despues);
    }

    #[Test]
    public function bloquea_direccion_contacto_y_canal_alternativo(): void
    {
        $bloqueadas = RestriccionCanal::OtaPreReserva->categoriasBloqueadas();

        self::assertFalse(CategoriaConocimiento::DireccionExacta->permitidaCon($bloqueadas));
        self::assertFalse(CategoriaConocimiento::Contacto->permitidaCon($bloqueadas));
        self::assertFalse(CategoriaConocimiento::CanalAlternativo->permitidaCon($bloqueadas));
    }

    /**
     * El corazón del asunto: lo que se restringe es la excepción. Cuántas habitaciones hay y
     * cuántas camas se contesta con normalidad, o se pierde la reserva — que fue lo que pasó.
     */
    #[Test]
    public function permite_lo_general_aunque_el_canal_restrinja(): void
    {
        $bloqueadas = RestriccionCanal::OtaPreReserva->categoriasBloqueadas();

        self::assertTrue(CategoriaConocimiento::General->permitidaCon($bloqueadas));
    }

    #[Test]
    public function el_canal_libre_no_bloquea_nada(): void
    {
        self::assertSame([], RestriccionCanal::Ninguna->categoriasBloqueadas());
        self::assertSame([], RestriccionCanal::Ninguna->variablesBloqueadas());
        self::assertSame('', RestriccionCanal::Ninguna->avisoParaElPrompt());
    }

    /**
     * Un enlace a sitio propio se salta de golpe toda la resta por categorías: saca la
     * conversación de la plataforma y la guía del otro lado lleva dentro la dirección, el wifi
     * y los teléfonos.
     */
    #[Test]
    public function bloquea_los_enlaces_fuera_de_plataforma(): void
    {
        $variables = RestriccionCanal::OtaPreReserva->variablesBloqueadas();

        self::assertContains('guide_url', $variables);
        self::assertContains('guide_path', $variables);
        self::assertContains('tours_catalog_url', $variables);
        self::assertContains('tours_catalog_path', $variables);
    }

    /** El aviso existe para que el modelo sepa EXPLICARLO, no para que se contenga. */
    #[Test]
    public function el_aviso_nombra_lo_prohibido_y_lo_permitido(): void
    {
        $aviso = RestriccionCanal::OtaPreReserva->avisoParaElPrompt();

        self::assertStringContainsString('WhatsApp', $aviso);
        self::assertStringContainsString('camas', $aviso, 'lo que SÍ se contesta también va dicho');
        self::assertStringContainsString('NO prometas', $aviso);
    }
}
