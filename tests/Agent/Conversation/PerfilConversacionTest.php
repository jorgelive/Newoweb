<?php

declare(strict_types=1);

namespace App\Tests\Agent\Conversation;

use App\Agent\Access\AgentActor;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Conversation\PerfilConversacion;
use App\Message\Contract\VinculoComercial;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Con quién cree el agente que está hablando.
 *
 * Aquí vive la regresión que costó el incidente. `deActor()` decidía por el TIPO DE CONTEXTO:
 * si la conversación colgaba de una reserva, quien escribía era huésped. Pero una consulta de
 * Airbnb **es** una reserva en estado `inquiry` — nadie ha reservado nada todavía—, así que a
 * una interesada que estaba decidiendo se le habló de «su reserva» y se le dio la dirección.
 *
 * Ahora decide el VÍNCULO. El test que lo fija es `una_consulta_de_ota_no_es_un_huesped`.
 */
final class PerfilConversacionTest extends TestCase
{
    private const string HILO = '019ff415-224e-7bd1-978b-6fef5e78d7ae';

    private static function huespedCon(VinculoComercial $vinculo): AgentActor
    {
        return AgentActor::huesped('beds24', 'pms_reserva', 'r', self::HILO, $vinculo, RestriccionCanal::Ninguna);
    }

    public static function vinculos(): iterable
    {
        yield 'confirmada => huésped' => [VinculoComercial::Cliente, PerfilConversacion::Huesped];
        yield 'consulta sin confirmar => interesado' => [VinculoComercial::Interesado, PerfilConversacion::Interesado];
        yield 'cancelada => interesado, se le puede volver a vender' => [VinculoComercial::Terminado, PerfilConversacion::Interesado];
        yield 'sin vínculo => prospecto, tenga o no contexto' => [VinculoComercial::Ninguno, PerfilConversacion::Prospecto];
    }

    #[Test]
    #[DataProvider('vinculos')]
    public function el_perfil_sale_del_vinculo(VinculoComercial $vinculo, PerfilConversacion $esperado): void
    {
        self::assertSame($esperado, PerfilConversacion::deActor(self::huespedCon($vinculo)));
    }

    /** El caso real: mismo `context_type` que una estancia confirmada, trato distinto. */
    #[Test]
    public function una_consulta_de_ota_no_es_un_huesped(): void
    {
        $perfil = PerfilConversacion::deActor(self::huespedCon(VinculoComercial::Interesado));

        self::assertNotSame(PerfilConversacion::Huesped, $perfil);
        self::assertSame(PerfilConversacion::Interesado, $perfil);
    }

    #[Test]
    public function el_prospecto_sin_contexto_sigue_siendo_prospecto(): void
    {
        $actor = AgentActor::prospecto('whatsapp_meta', self::HILO);

        self::assertSame(PerfilConversacion::Prospecto, PerfilConversacion::deActor($actor));
    }

    /**
     * Al interesado se le avisa de que NO es huésped todavía; al huésped no hace falta
     * decirle nada, que es el caso por defecto del prompt y añadir la línea sólo gasta
     * contexto.
     */
    #[Test]
    public function la_linea_volatil_distingue_al_interesado(): void
    {
        self::assertSame('', PerfilConversacion::Huesped->enUnaLinea());
        self::assertStringContainsString('SIN', PerfilConversacion::Interesado->enUnaLinea());
        self::assertStringContainsString('reservado nada', PerfilConversacion::Prospecto->enUnaLinea());
    }

    /**
     * El enum sólo tiene el eje de la RELACIÓN. Los prompts de negocio se fueron a
     * `InstruccionesDeDominioInterface`, y volver a meterlos aquí es lo que hacía que cada
     * perfil nuevo naciera hotelero.
     */
    #[Test]
    public function el_enum_ya_no_lleva_prompts_de_negocio(): void
    {
        self::assertFalse(
            method_exists(PerfilConversacion::class, 'instrucciones'),
            'los prompts de dominio no vuelven al enum de relación'
        );
    }
}
