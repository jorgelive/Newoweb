<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Agent;

use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Contract\InstruccionesDeDominioInterface;
use App\Pms\Service\Agent\PmsInstruccionesDominio;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El bloque de prompt que depende del NEGOCIO, separado de con quién se habla.
 *
 * Estos cinco prompts vivían dentro de `PerfilConversacion`, mezclados con el eje universal
 * de la relación, y por eso cada perfil nuevo nacía hotelero. Lo que se fija aquí es que la
 * separación aguanta y —sobre todo— que el argumento de venta directa NO se le dice a quien
 * llegó por una OTA.
 */
final class PmsInstruccionesDominioTest extends TestCase
{
    /**
     * Se instancia SIN constructor a propósito.
     *
     * `PmsEspacioEstancia` es `final` y no se puede doblar, y quitarle el `final` a una clase
     * de producción para acomodar un test es peor negocio. Nada de lo que se prueba aquí
     * —`supports()`, `esPorDefecto()`, `para()`— toca las dependencias: son texto y decisiones
     * sobre el propio enum. El día que se pruebe `contextoVolatil()`, que sí consulta, ese test
     * necesitará el contenedor y va en otra capa.
     */
    private function dominio(): PmsInstruccionesDominio
    {
        return (new \ReflectionClass(PmsInstruccionesDominio::class))->newInstanceWithoutConstructor();
    }

    #[Test]
    public function cumple_el_contrato_de_dominio(): void
    {
        self::assertInstanceOf(InstruccionesDeDominioInterface::class, $this->dominio());
    }

    #[Test]
    public function atiende_las_reservas_del_pms_y_no_otros_dominios(): void
    {
        $dominio = $this->dominio();

        self::assertTrue($dominio->supports('pms_reserva'));
        self::assertFalse($dominio->supports('tour_reserva'));
        self::assertFalse($dominio->supports('soporte'));
    }

    /** Quien pregunta sin contexto pregunta, hoy por hoy, por alojamiento. */
    #[Test]
    public function es_el_dominio_por_defecto_mientras_sea_el_unico(): void
    {
        self::assertTrue($this->dominio()->esPorDefecto());
    }

    #[Test]
    public function cada_perfil_tiene_su_bloque(): void
    {
        $dominio = $this->dominio();

        foreach (PerfilConversacion::cases() as $perfil) {
            self::assertNotSame('', $dominio->para($perfil), "el perfil {$perfil->value} se quedó sin bloque");
        }
    }

    /**
     * El fallo que no llegó a producción de milagro.
     *
     * El prompt del prospecto vende con «reservando directo se lo ahorra». Dicho a alguien que
     * llegó por Airbnb, eso es invitarle a saltarse la plataforma: incumplimiento de contrato
     * y riesgo de deslistado. Por eso el interesado tiene su propio bloque y no reutiliza el
     * del prospecto.
     */
    #[Test]
    public function al_interesado_no_se_le_ofrece_saltarse_la_ota(): void
    {
        $dominio = $this->dominio();

        $interesado = $dominio->para(PerfilConversacion::Interesado);
        $prospecto = $dominio->para(PerfilConversacion::Prospecto);

        self::assertStringNotContainsString('se lo ahorra', $interesado);
        self::assertStringContainsString('NO LE OFREZCAS RESERVAR POR OTRO SITIO', $interesado);
        self::assertStringContainsString('se lo ahorra', $prospecto, 'al directo sí se le vende así');
    }

    /**
     * Las cinco promesas de fotos que nunca llegaron. Si no puede salir por el canal, no va a
     * llegar, y prometerlo sólo consigue que el huésped espere y vuelva a preguntar.
     */
    #[Test]
    public function al_interesado_se_le_prohibe_prometer_envios(): void
    {
        self::assertStringContainsString(
            'NUNCA prometas',
            $this->dominio()->para(PerfilConversacion::Interesado)
        );
    }
}
