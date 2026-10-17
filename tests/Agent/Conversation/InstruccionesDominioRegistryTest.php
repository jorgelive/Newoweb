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
    public function el_de_tours_le_vende_al_prospecto_y_al_interesado_de_un_expediente(): void
    {
        $tours = new CotizacionInstruccionesDominio();

        self::assertStringContainsString('consultar_tours', $tours->para(PerfilConversacion::Prospecto));
        self::assertStringContainsString('anotar_pedido_cotizacion', $tours->para(PerfilConversacion::Prospecto));
        self::assertStringContainsString('consultar_tours', $tours->para(PerfilConversacion::Interesado));
        self::assertSame('', $tours->para(PerfilConversacion::Personal));
        self::assertFalse($tours->esPorDefecto(), 'Sólo puede haber un por defecto, y es el alojamiento.');
        self::assertFalse($tours->supports(null));
        self::assertTrue($tours->supports('cotizacion_file'));
    }

    #[Test]
    public function un_expediente_no_recibe_las_reglas_del_alojamiento(): void
    {
        // Hasta el 16/10/2026 caía al camino sin contexto y el interesado de un tour oía «NO DES
        // PRECIOS, lo ve en el anuncio de la plataforma».
        $registro = new InstruccionesDominioRegistry([
            new CotizacionInstruccionesDominio(),
            $this->dominio('alojamiento', 'pms_reserva', true, 'REGLAS DE OTA'),
        ]);

        $prompt = $registro->para('cotizacion_file', PerfilConversacion::Interesado);

        self::assertStringContainsString('consultar_tours', $prompt);
        self::assertStringNotContainsString('REGLAS DE OTA', $prompt);
        self::assertSame('', $registro->contextoVolatil('cotizacion_file', $this->createStub(ActorInterface::class), MapaDeHitos::vacio()));
    }

    #[Test]
    public function al_huesped_de_una_casita_turismo_se_le_presenta_detras_del_alojamiento(): void
    {
        $registro = new InstruccionesDominioRegistry([
            new CotizacionInstruccionesDominio(),
            $this->dominio('alojamiento', 'pms_reserva', true, 'REGLAS DEL HUÉSPED'),
        ]);

        $huesped = $registro->para('pms_reserva', PerfilConversacion::Huesped);
        self::assertStringStartsWith('REGLAS DEL HUÉSPED', $huesped);
        self::assertStringContainsString('TAMBIÉN SOMOS AGENCIA', $huesped);

        // A una consulta de OTA no se le ofrece nada fuera de la plataforma.
        self::assertSame('REGLAS DEL HUÉSPED', $registro->para('pms_reserva', PerfilConversacion::Interesado));
    }

    #[Test]
    public function si_el_dominio_propio_calla_habla_el_resto(): void
    {
        // Un compañero del equipo en un expediente conserva su bloque de siempre.
        $registro = new InstruccionesDominioRegistry([
            new CotizacionInstruccionesDominio(),
            $this->dominio('alojamiento', 'pms_reserva', true, 'BLOQUE DEL EQUIPO'),
        ]);

        self::assertSame('BLOQUE DEL EQUIPO', $registro->para('cotizacion_file', PerfilConversacion::Personal));
    }
}
