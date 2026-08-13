<?php

declare(strict_types=1);

namespace App\Tests\Agent\Entity;

use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Entity\AgentConocimiento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A quién se le puede contar cada respuesta.
 *
 * Es la parte del conocimiento que no puede fallar: aquí se guardan procedimientos internos y
 * códigos junto a «¿hay estacionamiento?», y lo que separa unos de otros son estas dos listas.
 */
final class AgentConocimientoTest extends TestCase
{
    /** @param list<string> $dominios @param list<string> $perfiles */
    private function item(array $dominios = [], array $perfiles = [], bool $activo = true): AgentConocimiento
    {
        return (new AgentConocimiento())
            ->setDominios($dominios)
            ->setPerfiles($perfiles)
            ->setActivo($activo);
    }

    /**
     * ⚠️ Vacío = SIN ACOTAR, la misma convención que el resto del agente.
     *
     * Es deliberado y va contra la intuición: un olvido al clasificar deja el ítem de más
     * —inofensivo, se ve alguna vez de sobra— en vez de invisible. Lo segundo no se descubre
     * nunca, porque nadie echa de menos una respuesta que no sabía que existía.
     */
    #[Test]
    public function sin_listas_se_le_cuenta_a_cualquiera(): void
    {
        $item = $this->item();

        self::assertTrue($item->visiblePara(['hotelero'], PerfilConversacion::Prospecto));
        self::assertTrue($item->visiblePara(['turistico'], PerfilConversacion::Personal));
        self::assertTrue($item->visiblePara([], PerfilConversacion::Huesped));
    }

    /** Lo que es de un negocio no se le ofrece a quien no llega a él. */
    #[Test]
    public function un_item_de_un_negocio_no_se_le_cuenta_a_otro(): void
    {
        $item = $this->item(dominios: ['turistico']);

        self::assertTrue($item->visiblePara(['turistico'], PerfilConversacion::Huesped));
        self::assertFalse($item->visiblePara(['hotelero'], PerfilConversacion::Huesped));
    }

    /** Y quien llega a los dos lo ve: la intersección basta con que no sea vacía. */
    #[Test]
    public function quien_llega_a_los_dos_negocios_lo_ve(): void
    {
        self::assertTrue(
            $this->item(dominios: ['turistico'])->visiblePara(['hotelero', 'turistico'], PerfilConversacion::Huesped)
        );
    }

    /**
     * El filtro que de verdad importa: un procedimiento interno no se le lee a un huésped.
     */
    #[Test]
    public function lo_del_equipo_no_se_le_cuenta_al_huesped(): void
    {
        $item = $this->item(perfiles: ['personal', 'colaborador']);

        self::assertTrue($item->visiblePara([], PerfilConversacion::Personal));
        self::assertFalse($item->visiblePara([], PerfilConversacion::Huesped));
        self::assertFalse($item->visiblePara([], PerfilConversacion::Prospecto));
    }

    /** Apagarlo lo saca para todos, sin borrarlo. */
    #[Test]
    public function un_item_apagado_no_se_le_cuenta_a_nadie(): void
    {
        self::assertFalse($this->item(activo: false)->visiblePara(['hotelero'], PerfilConversacion::Personal));
    }

    /**
     * Un actor sin dominios poblados no se queda sin conocimiento.
     *
     * Mismo criterio que `SkillRegistry`: mientras el modelo de dominios se termina de enchufar,
     * un actor sin poblar se comporta como antes de que existieran.
     */
    #[Test]
    public function un_actor_sin_dominios_no_se_acota(): void
    {
        self::assertTrue($this->item(dominios: ['turistico'])->visiblePara([], PerfilConversacion::Huesped));
    }

    /** La línea que ve el modelo lleva el id y las etiquetas — nunca el contenido. */
    #[Test]
    public function la_linea_del_prompt_no_lleva_el_contenido(): void
    {
        $item = $this->item()
            ->setEtiquetas('estacionamiento, parking, dónde dejo el auto')
            ->setContenido('Sí, hay estacionamiento gratuito para dos autos.');

        $linea = $item->comoLinea();

        self::assertStringContainsString('estacionamiento, parking', $linea);
        self::assertStringNotContainsString('gratuito', $linea, 'el contenido se da sólo cuando ya eligió');
    }
}
