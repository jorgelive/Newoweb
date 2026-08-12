<?php

declare(strict_types=1);

namespace App\Tests\Agent\Access;

use App\Agent\Access\AgentActor;
use App\Agent\Access\RestriccionCanal;
use App\Message\Contract\VinculoComercial;
use App\Security\Roles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La frontera del actor: qué puede ver y qué no, según quién es.
 *
 * El caso que da nombre a media clase: el prospecto **nace sin contexto a propósito**, y eso
 * es lo que le cierra las siete skills acotadas a una reserva sin tener que enumerarlas. Pero
 * sin saber en qué CONVERSACIÓN está tampoco podía escalar, así que todo lead que pedía hablar
 * con alguien moría en «No sé de qué conversación hablas».
 *
 * Darle contexto habría arreglado el síntoma abriéndole de paso todo lo demás. Por eso son dos
 * datos —`contextoId()` es permiso, `conversacionId()` es enrutado— y por eso esa distinción
 * se prueba aquí: si alguien los vuelve a fundir, esto se pone rojo.
 */
final class AgentActorTest extends TestCase
{
    private const string HILO = '019ff415-224e-7bd1-978b-6fef5e78d7ae';

    #[Test]
    public function el_prospecto_conoce_su_hilo_pero_no_tiene_contexto(): void
    {
        $actor = AgentActor::prospecto('beds24', self::HILO);

        self::assertSame(self::HILO, $actor->conversacionId(), 'sin hilo no puede escalar');
        self::assertNull($actor->contextoId(), 'con contexto se le abrirían las skills acotadas');
        self::assertNull($actor->contextoTipo());
        self::assertTrue($actor->esProspecto());
        self::assertFalse($actor->esDelEquipo());
    }

    #[Test]
    public function el_prospecto_sin_hilo_sigue_siendo_valido(): void
    {
        $actor = AgentActor::prospecto('whatsapp_meta');

        self::assertNull($actor->conversacionId());
        self::assertNull($actor->contextoId());
        self::assertTrue($actor->tieneAlguno([Roles::PROSPECTO]));
        self::assertFalse($actor->tieneAlguno([Roles::HUESPED]));
    }

    #[Test]
    public function el_huesped_conserva_su_contexto_y_suma_el_hilo(): void
    {
        $actor = AgentActor::huesped('beds24', 'pms_reserva', 'reserva-123', self::HILO);

        self::assertSame('reserva-123', $actor->contextoId());
        self::assertSame('pms_reserva', $actor->contextoTipo());
        self::assertSame(self::HILO, $actor->conversacionId());
        self::assertFalse($actor->esProspecto());
    }

    /** Los llamantes que existían antes del hilo no se rompen. */
    #[Test]
    public function el_huesped_construido_con_tres_argumentos_sigue_funcionando(): void
    {
        $actor = AgentActor::huesped('beds24', 'pms_reserva', 'reserva-123');

        self::assertSame('reserva-123', $actor->contextoId());
        self::assertNull($actor->conversacionId());
    }

    #[Test]
    public function el_vinculo_y_la_restriccion_viajan_con_el_actor(): void
    {
        $actor = AgentActor::huesped(
            'beds24',
            'pms_reserva',
            'reserva-lu',
            self::HILO,
            VinculoComercial::Interesado,
            RestriccionCanal::OtaPreReserva
        );

        self::assertSame(VinculoComercial::Interesado, $actor->vinculo());
        self::assertTrue($actor->restriccion()->restringe());
    }

    /** Sin declarar vínculo, un actor con contexto es cliente: es como se comportaba antes. */
    #[Test]
    public function el_huesped_sin_vinculo_declarado_es_cliente(): void
    {
        $actor = AgentActor::huesped('beds24', 'pms_reserva', 'r');

        self::assertSame(VinculoComercial::Cliente, $actor->vinculo());
        self::assertFalse($actor->restriccion()->restringe());
    }
}
