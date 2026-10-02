<?php

declare(strict_types=1);

namespace App\Tests\Message\Dto;

use App\Message\Dto\AsuntoSinTelefono;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Por qué no le sale un WhatsApp a un asunto. Lo que decide es el HILO —lo mismo que mira el
 * envío—, no la ficha: una reserva con el número en la ficha y no en su hilo no recibe nada.
 */
final class AsuntoSinTelefonoTest extends TestCase
{
    #[Test]
    public function con_telefono_en_el_hilo_y_sin_veto_no_entra_en_el_reporte(): void
    {
        self::assertNull(AsuntoSinTelefono::motivo(true, '51984123456', false, null));
    }

    #[Test]
    public function sin_hilo(): void
    {
        self::assertSame(AsuntoSinTelefono::SIN_CONVERSACION, AsuntoSinTelefono::motivo(false, null, false, '51984123456'));
    }

    #[Test]
    public function el_numero_solo_en_la_ficha_es_el_caso_de_adrian(): void
    {
        self::assertSame(AsuntoSinTelefono::SOLO_EN_LA_FICHA, AsuntoSinTelefono::motivo(true, null, false, '5493884040780'));
        self::assertSame(AsuntoSinTelefono::SOLO_EN_LA_FICHA, AsuntoSinTelefono::motivo(true, '  ', false, '5493884040780'));
    }

    #[Test]
    public function sin_telefono_en_ningun_sitio(): void
    {
        self::assertSame(AsuntoSinTelefono::SIN_TELEFONO, AsuntoSinTelefono::motivo(true, null, false, null));
        self::assertSame(AsuntoSinTelefono::SIN_TELEFONO, AsuntoSinTelefono::motivo(true, '', false, ''));
    }

    #[Test]
    public function con_telefono_pero_vetado(): void
    {
        self::assertSame(AsuntoSinTelefono::WHATSAPP_VETADO, AsuntoSinTelefono::motivo(true, '51984123456', true, null));
    }
}
