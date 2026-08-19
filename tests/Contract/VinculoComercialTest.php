<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Message\Contract\MessageContextInterface;
use App\Contract\VinculoComercial;
use App\Pms\Service\Message\PmsReservaMessageContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué relación tiene con nosotros quien escribe.
 *
 * `deStatusTag()` sobrevive sólo como fallback para conversaciones anteriores al campo: quién
 * es cliente lo declara ahora cada dominio (`MessageContextInterface::getVinculo()`), porque
 * en alojamiento lo decide el estado de la reserva y en un tour podría decidirlo el pago.
 */
final class VinculoComercialTest extends TestCase
{
    public static function etiquetas(): iterable
    {
        yield 'confirmed' => ['confirmed', VinculoComercial::Cliente];
        yield 'inquiry' => ['inquiry', VinculoComercial::Interesado];
        yield 'cancelled' => ['cancelled', VinculoComercial::Terminado];
        yield 'sin etiqueta' => [null, VinculoComercial::Ninguno];
    }

    #[Test]
    #[DataProvider('etiquetas')]
    public function traduce_la_etiqueta_de_estado(?string $tag, VinculoComercial $esperado): void
    {
        self::assertSame($esperado, VinculoComercial::deStatusTag($tag));
    }

    /**
     * Ante la duda, el trato más restringido. Equivocarse hacia `Interesado` cuesta una venta
     * algo torpe; hacia `Cliente` cuesta filtrar datos que el partner prohíbe dar.
     */
    #[Test]
    public function un_estado_desconocido_cae_del_lado_prudente(): void
    {
        self::assertSame(VinculoComercial::Interesado, VinculoComercial::deStatusTag('pagado'));
        self::assertNotSame(VinculoComercial::Cliente, VinculoComercial::deStatusTag('vete-a-saber'));
    }

    #[Test]
    public function se_le_vende_a_quien_no_ha_cerrado(): void
    {
        self::assertTrue(VinculoComercial::Ninguno->seLeVende());
        self::assertTrue(VinculoComercial::Interesado->seLeVende());
        self::assertFalse(VinculoComercial::Cliente->seLeVende());
    }

    #[Test]
    public function solo_interesado_y_cliente_tienen_contexto_vivo(): void
    {
        self::assertTrue(VinculoComercial::Interesado->tieneContextoVivo());
        self::assertTrue(VinculoComercial::Cliente->tieneContextoVivo());
        self::assertFalse(VinculoComercial::Ninguno->tieneContextoVivo());
        self::assertFalse(VinculoComercial::Terminado->tieneContextoVivo());
    }

    /**
     * El contrato es lo que impide volver al acuerdo tácito de strings mágicos entre el agente
     * y el PMS. Si alguien lo quita, un dominio nuevo vuelve a caer en el default sin avisar.
     */
    #[Test]
    public function el_vinculo_lo_declara_el_dominio(): void
    {
        self::assertTrue(method_exists(MessageContextInterface::class, 'getVinculo'));
        self::assertTrue(method_exists(PmsReservaMessageContext::class, 'getVinculo'));
    }
}
