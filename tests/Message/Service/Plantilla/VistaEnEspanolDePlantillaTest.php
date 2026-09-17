<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Plantilla;

use App\Message\Entity\MessageTemplate;
use App\Message\Service\Plantilla\VistaEnEspanolDePlantilla;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La ficha enseña el español, no el JSON de siete idiomas.
 */
final class VistaEnEspanolDePlantillaTest extends TestCase
{
    #[Test]
    public function el_de_meta_trae_cabecera_cuerpo_pie_y_botones(): void
    {
        $plantilla = (new MessageTemplate())->setWhatsappMetaTmpl([
            'header' => [['language' => 'es', 'format' => 'TEXT', 'content' => 'Tu llegada, {{guest_name}}']],
            'body' => [
                ['language' => 'es', 'content' => 'Desde hoy tienes tus llaves.'],
                ['language' => 'en', 'content' => 'From today you have your keys.'],
            ],
            'footer' => [['language' => 'es', 'content' => 'Buen viaje']],
            'buttons_map' => [[
                'index' => 0,
                'type' => 'url',
                'content' => 'https://pax.openperu.pe/{{1}}',
                'resolver_key' => 'guide_path',
                'button_text' => [['language' => 'es', 'content' => 'Ver mi guía']],
            ]],
        ]);

        self::assertSame(
            "[Cabecera] Tu llegada, {{guest_name}}\n\nDesde hoy tienes tus llaves.\n\n[Pie] Buen viaje\n\n[Botón] Ver mi guía → guide_path",
            (new VistaEnEspanolDePlantilla())->whatsappMeta($plantilla)
        );
    }

    #[Test]
    public function no_se_cuela_ningun_otro_idioma(): void
    {
        $plantilla = (new MessageTemplate())->setBeds24Tmpl([
            'body' => [
                ['language' => 'es', 'content' => 'Hola {{guest_name}}'],
                ['language' => 'de', 'content' => 'Hallo {{guest_name}}'],
            ],
        ]);

        self::assertSame('Hola {{guest_name}}', (new VistaEnEspanolDePlantilla())->beds24($plantilla));
    }

    #[Test]
    public function un_canal_sin_texto_se_ve_vacio(): void
    {
        // Vacío y no «[]»: quien lo pinta decide cómo decir que no hay nada escrito.
        self::assertSame('', (new VistaEnEspanolDePlantilla())->whatsappDentro((new MessageTemplate())->setWhatsappLinkTmpl(['body' => []])));
    }

    #[Test]
    public function el_correo_lleva_su_asunto_delante(): void
    {
        $plantilla = (new MessageTemplate())->setEmailTmpl([
            'subject' => [['language' => 'es', 'content' => 'Tu reserva']],
            'body' => [['language' => 'es', 'content' => 'Gracias por reservar.']],
        ]);

        self::assertSame("[Asunto] Tu reserva\n\nGracias por reservar.", (new VistaEnEspanolDePlantilla())->correo($plantilla));
    }
}
