<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill;

use App\Agent\Skill\Pms\ConsultarGuiaSkill;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Qué le llega al agente de los bloques que el navegador pintaría y aquí no hay quien pinte.
 *
 * `resolverBloquesDelFront()` es privado a propósito —nadie fuera de la skill decide esto— y aquí
 * se llama por reflexión: lo que se quiere fijar es el CONTRATO de qué sobrevive y qué no, que es
 * justo lo que cambió el 12/09/2026 y lo que nadie notaría si volviera a cambiar.
 */
final class ConsultarGuiaSkillMediaTest extends TestCase
{
    private const HOST = 'https://pax.openperu.pe';

    private function resolver(string $texto): string
    {
        // Sin constructor y a propósito: `resolverBloquesDelFront()` no toca ninguna colaboradora
        // —sólo el host—, y las que pide el constructor son `final`, así que no admiten doble. Un
        // contenedor entero para ejercitar una sustitución de texto sería pagar de más y probar
        // otra cosa.
        $skill = (new \ReflectionClass(ConsultarGuiaSkill::class))->newInstanceWithoutConstructor();

        $host = new \ReflectionProperty(ConsultarGuiaSkill::class, 'hostPax');
        $host->setValue($skill, self::HOST);

        $metodo = new ReflectionMethod($skill, 'resolverBloquesDelFront');

        return $metodo->invoke(
            $skill,
            $texto,
            new \App\Pms\Guia\PmsGuiaContexto(),
            \App\Pms\Guia\PmsGuiaAcceso::publico(),
            'es',
        );
    }

    #[Test]
    public function el_croquis_llega_como_enlace_absoluto(): void
    {
        // 🔥 Esto se BORRABA hasta el 12/09/2026, y era el agujero entero: al agente le tocaba
        // describir con palabras una puerta que sólo se identifica en un plano, teniendo el plano
        // al lado. Ver `docs/PmsGuiaHuesped.md` §3.c.
        $salida = $this->resolver('Tu puerta {{ img: /carga/pms/pms_unidad/images/croquis.webp }} es esa.');

        self::assertStringContainsString(
            self::HOST . '/carga/pms/pms_unidad/images/croquis.webp',
            $salida
        );
    }

    #[Test]
    public function la_ruta_relativa_recibe_host_y_la_absoluta_no_se_toca(): void
    {
        // El interpolador entrega RUTA para los archivos y URL entera para los vídeos de YouTube.
        // Sin host, el enlace muere en cuanto sale de aquí —esto acaba en un WhatsApp—; con host
        // duplicado, muere igual.
        self::assertStringContainsString(
            self::HOST . '/carga/x.webp',
            $this->resolver('{{ img: /carga/x.webp }}')
        );

        $youtube = 'https://www.youtube.com/watch?v=abc123';
        $salida = $this->resolver(sprintf('{{ video: %s }}', $youtube));

        self::assertStringContainsString($youtube, $salida);
        self::assertStringNotContainsString(self::HOST . '/https', $salida);
    }

    #[Test]
    public function un_medio_bloqueado_entrega_su_motivo_y_no_su_url(): void
    {
        // Existir y no tocar todavía NO es lo mismo que no existir. Si se borrara, el agente
        // respondería como si el vídeo no estuviera, y no lo enseñaría ni cuando se abra la
        // ventana. Y la URL no puede viajar: para eso estaba bloqueado.
        $salida = $this->resolver('Mira {{ videobloqueado: Disponible 30 h antes de tu llegada }}.');

        self::assertStringContainsString('Disponible 30 h antes de tu llegada', $salida);
        self::assertStringNotContainsString('carga/', $salida);
    }

    #[Test]
    public function el_mapa_y_los_widgets_siguen_borrandose(): void
    {
        // Eso sí es maquetación: sin navegador que los pinte no queda nada que contar, y leerlos
        // en voz alta es peor que callarlos.
        $salida = $this->resolver('Estamos aquí {{ map: -13.5,-71.9 }} y {{ widget: wifi }} listo.');

        self::assertStringNotContainsString('{{', $salida);
        self::assertStringNotContainsString('map', $salida);
        self::assertStringNotContainsString('-13.5', $salida);
    }

    #[Test]
    public function el_wifi_y_los_medios_de_pago_siguen_remitiendo_a_su_skill(): void
    {
        // No es una errata del editor: el front los pinta con datos que son de OTRA skill. Sin la
        // remisión, el modelo veía que no había ninguna cuenta y se la inventaba.
        $salida = $this->resolver('{{ wifi_data }} y {{ medios_pago }}');

        self::assertStringContainsString('consultar_wifi', $salida);
        self::assertStringContainsString('consultar_medios_pago', $salida);
    }

    #[Test]
    public function una_clave_suelta_que_nadie_resolvio_no_se_le_lee_al_huesped(): void
    {
        $salida = $this->resolver('Tu código es {{ inventado_por_el_editor }} y ya.');

        self::assertStringNotContainsString('{{', $salida);
        self::assertStringNotContainsString('inventado_por_el_editor', $salida);
    }
}
