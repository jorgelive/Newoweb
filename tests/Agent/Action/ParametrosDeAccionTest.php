<?php

declare(strict_types=1);

namespace App\Tests\Agent\Action;

use App\Agent\Action\ParametrosDeAccion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los parámetros que una regla del autorresponder le pasa a su acción.
 *
 * El origen es un `json` que teclea un operador en EasyAdmin, así que la mitad de estos casos
 * son formas de teclearlo mal. Antes esto era un `array` pelado con su entrada en el baseline:
 * cada implementación se defendía por su cuenta, o no se defendía.
 *
 * Unitario puro.
 */
final class ParametrosDeAccionTest extends TestCase
{
    #[Test]
    public function lee_lo_que_configuro_el_operador(): void
    {
        $p = ParametrosDeAccion::desdeCrudo([
            'template_code' => 'bienvenida_booking',
            'force_channel' => 'beds24',
        ]);

        self::assertSame('bienvenida_booking', $p->texto('template_code'));
        self::assertSame('beds24', $p->texto('force_channel'));
        self::assertTrue($p->tiene('force_channel'));
    }

    #[Test]
    public function lo_que_no_esta_es_null_y_no_revienta(): void
    {
        $p = ParametrosDeAccion::desdeCrudo(['template_code' => 'x']);

        self::assertNull($p->texto('force_channel'));
        self::assertFalse($p->tiene('force_channel'));
    }

    #[Test]
    public function el_json_vacio_o_ausente_se_acepta(): void
    {
        foreach ([null, []] as $crudo) {
            $p = ParametrosDeAccion::desdeCrudo($crudo);

            self::assertNull($p->texto('template_code'));
            self::assertSame([], $p->todos());
        }
    }

    #[Test]
    public function una_cadena_vacia_o_de_espacios_cuenta_como_ausente(): void
    {
        // Es como queda un campo que alguien abrió y no rellenó. Tratarlo como valor haría que
        // `force_channel: ""` buscara un canal con id vacío en vez de caer al canal de entrada.
        $p = ParametrosDeAccion::desdeCrudo(['force_channel' => '   ', 'template_code' => '']);

        self::assertNull($p->texto('force_channel'));
        self::assertNull($p->texto('template_code'));
        self::assertFalse($p->tiene('force_channel'));
    }

    #[Test]
    public function un_numero_se_lee_como_texto(): void
    {
        // Los códigos de plantilla son texto, pero un json admite `123` sin comillas.
        self::assertSame('123', ParametrosDeAccion::desdeCrudo(['template_code' => 123])->texto('template_code'));
    }

    #[Test]
    public function lo_que_no_es_escalar_se_descarta_en_la_puerta(): void
    {
        // Un array anidado en un parámetro de configuración es un error de tecleo. Arrastrarlo
        // dentro sólo cambia el sitio donde revienta.
        $p = ParametrosDeAccion::desdeCrudo([
            'template_code' => 'ok',
            'raro' => ['a' => 1],
        ]);

        self::assertSame(['template_code' => 'ok'], $p->todos());
        self::assertNull($p->texto('raro'));
    }

    #[Test]
    public function un_booleano_no_es_texto(): void
    {
        // `true` como código de plantilla daría la cadena "1" y buscaría una plantilla llamada
        // «1». Mejor decir que no hay valor.
        self::assertNull(ParametrosDeAccion::desdeCrudo(['template_code' => true])->texto('template_code'));
    }
}
