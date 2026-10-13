<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Entity;

use App\Cotizacion\Entity\CotizacionCatalogo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué catálogos cuenta el agente del chat a quien pregunte. Ver
 * `CotizacionCatalogo::loOfreceElAgente()` y docs/Agent.md §5.8.
 *
 * ⚠️ El DQL de `CatalogoWebLector::paraElAgente()` repite esta condición: si cambia aquí, allí.
 */
final class CatalogoOfrecidoPorAgenteTest extends TestCase
{
    private function catalogo(bool $activo, bool $web, bool $agente): CotizacionCatalogo
    {
        return (new CotizacionCatalogo())
            ->setActivo($activo)
            ->setPublicadoWeb($web)
            ->setOfrecidoPorAgente($agente);
    }

    #[Test]
    public function lo_publicado_en_la_web_lo_ofrece_siempre_sin_otra_casilla(): void
    {
        self::assertTrue($this->catalogo(true, true, false)->loOfreceElAgente());
    }

    #[Test]
    public function lo_marcado_para_el_agente_sale_aunque_no_este_en_la_web(): void
    {
        // Los traslados: se venden por WhatsApp sin ocupar una sección vacía en la portada.
        self::assertTrue($this->catalogo(true, false, true)->loOfreceElAgente());
    }

    #[Test]
    public function lo_hecho_para_un_cliente_no_se_cuenta_a_cualquiera(): void
    {
        self::assertFalse($this->catalogo(true, false, false)->loOfreceElAgente(), 'Contarlo por WhatsApp a cualquiera es publicarlo.');
    }

    #[Test]
    public function un_catalogo_inactivo_no_lo_ofrece_nadie(): void
    {
        self::assertFalse($this->catalogo(false, true, true)->loOfreceElAgente());
    }
}
