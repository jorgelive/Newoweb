<?php

declare(strict_types=1);

namespace App\Tests\Pms\Guia;

use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaInterpolador;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `{{ mapa }}` sale del establecimiento (23/10/2026). Estaba escrito como `{{ map: … }}` en ocho
 * copias de la ficha «Ubicación (general)».
 */
final class MapaDelEstablecimientoTest extends TestCase
{
    private const string URL = 'https://www.google.com/maps/place/-13.511305,-71.984414';

    #[Test]
    public function el_marcador_se_convierte_en_el_bloque_del_mapa(): void
    {
        $contexto = new PmsGuiaContexto(medios: ['mapa' => ['valor' => self::URL, 'nivel' => PmsGuiaVisibilidad::Publico]]);

        self::assertSame(
            'Calle Saphi 877-A y B. {{ map: ' . self::URL . ' }}',
            (new PmsGuiaInterpolador())->interpolarUno('Calle Saphi 877-A y B. {{ mapa }}', $contexto, PmsGuiaAcceso::publico(), 'es'),
        );
    }

    #[Test]
    public function sin_mapa_cargado_el_marcador_se_quita_y_la_palabra_mapa_no_se_toca(): void
    {
        self::assertSame(
            'Mira el mapa: ',
            (new PmsGuiaInterpolador())->interpolarUno('Mira el mapa: {{ mapa }}', new PmsGuiaContexto(), PmsGuiaAcceso::publico(), 'es'),
        );
    }
}
