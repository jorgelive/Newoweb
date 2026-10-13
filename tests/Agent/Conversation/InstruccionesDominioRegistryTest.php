<?php

declare(strict_types=1);

namespace App\Tests\Agent\Conversation;

use App\Agent\Access\ActorInterface;
use App\Agent\Contract\InstruccionesDeDominioInterface;
use App\Agent\Conversation\InstruccionesDominioRegistry;
use App\Agent\Conversation\PerfilConversacion;
use App\Contract\MapaDeHitos;
use App\Cotizacion\Service\Agent\CotizacionInstruccionesDominio;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué negocio le habla a cada conversación.
 *
 * Lo que se fija: con contexto reconocido habla SÓLO su dominio; sin él —el prospecto— hablan
 * todos, el por defecto delante. Hasta el 11/10/2026 el prospecto sólo oía del alojamiento y
 * negó un tour a Machu Picchu publicado en la web. Ver docs/Agent.md.
 */
final class InstruccionesDominioRegistryTest extends TestCase
{
    private function dominio(string $nombre, ?string $contexto, bool $porDefecto, string $bloque = ''): InstruccionesDeDominioInterface
    {
        return new class ($nombre, $contexto, $porDefecto, $bloque) implements InstruccionesDeDominioInterface {
            public function __construct(
                private string $nombre,
                private ?string $contexto,
                private bool $porDefecto,
                private string $bloque,
            ) {
            }

            public function supports(?string $contextType): bool
            {
                return $contextType !== null && $contextType === $this->contexto;
            }

            public function esPorDefecto(): bool
            {
                return $this->porDefecto;
            }

            public function para(PerfilConversacion $perfil): string
            {
                return $this->bloque !== '' ? $this->bloque : $this->nombre;
            }

            public function contextoVolatil(ActorInterface $actor, MapaDeHitos $hitos): string
            {
                return 'volátil de ' . $this->nombre;
            }
        };
    }

    /** El de tours se registra ANTES a propósito: el orden del contenedor no debe decidir. */
    private function registro(): InstruccionesDominioRegistry
    {
        return new InstruccionesDominioRegistry([
            $this->dominio('tours', null, false),
            $this->dominio('alojamiento', 'pms_reserva', true),
        ]);
    }

    #[Test]
    public function con_contexto_reconocido_habla_solo_su_dominio(): void
    {
        self::assertSame('alojamiento', $this->registro()->para('pms_reserva', PerfilConversacion::Huesped));
    }

    #[Test]
    public function sin_contexto_hablan_todos_con_el_por_defecto_delante(): void
    {
        self::assertSame("alojamiento\n\ntours", $this->registro()->para(null, PerfilConversacion::Prospecto));
        self::assertSame("alojamiento\n\ntours", $this->registro()->para('manual', PerfilConversacion::Prospecto));
    }

    #[Test]
    public function el_contexto_volatil_sigue_siendo_de_uno_solo(): void
    {
        $actor = $this->createStub(ActorInterface::class);

        self::assertSame('volátil de alojamiento', $this->registro()->contextoVolatil(null, $actor, MapaDeHitos::vacio()));
    }

    #[Test]
    public function el_de_tours_le_vende_al_prospecto_y_no_a_la_consulta_de_ota(): void
    {
        $tours = new CotizacionInstruccionesDominio();

        self::assertStringContainsString('consultar_tours', $tours->para(PerfilConversacion::Prospecto));
        self::assertStringContainsString('anotar_pedido_cotizacion', $tours->para(PerfilConversacion::Prospecto));
        self::assertSame('', $tours->para(PerfilConversacion::Interesado), 'La consulta de una OTA se cierra en la plataforma.');
        self::assertSame('', $tours->para(PerfilConversacion::Personal));
        self::assertFalse($tours->esPorDefecto(), 'Sólo puede haber un por defecto, y es el alojamiento.');
        self::assertFalse($tours->supports(null));
    }
}
