<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Cómo se le presenta el precio al cliente: una sola decisión, cuatro respuestas (14/10/2026).
 *
 * Sustituye a dos booleanos de `Cotizacion` —`precioOculto` y `totalesOcultos`— que respondían a la
 * misma pregunta por partes y dejaban combinaciones sin sentido («sin precio» con «totales a la
 * vista»). Y le añade la que faltaba: con varios perfiles (Nacional / Extranjero) la nota «calculado
 * para N pasajeros» de la tarjeta no se podía quitar sin enseñar en la guía un total de un grupo
 * mezclado, que no describe a nadie.
 *
 *   SIN_PRECIO        guía sin precios · tarjeta sin precio
 *   TOTAL_GRUPO       guía con total   · tarjeta: total para N + por persona
 *   POR_PERSONA_BASE  guía sin total   · tarjeta: por persona · calculado para N
 *   POR_PERSONA       guía sin total   · tarjeta: por persona, sin nota
 *
 * Lo que la tarjeta hace con cada opción lo decide `TourTarjetaResolver` (`totalDelGrupo()` y
 * `paxBaseGrupo()`); la guía sigue leyendo los booleanos de siempre, que ahora son DERIVADOS
 * (`Cotizacion::isPrecioOculto()`, `isTotalesOcultos()`). Ver docs/Cotizaciones.md, «El precio
 * desde».
 *
 * ⚠️ Espejo TypeScript: `PRESENTACIONES_PRECIO` en `util/src/types/cotizacionEditorModel.ts` (las
 * etiquetas del selector del editor). Las claves las da el esquema generado.
 */
enum PresentacionPrecioEnum: string
{
    case SIN_PRECIO = 'sin_precio';
    case TOTAL_GRUPO = 'total_grupo';
    case POR_PERSONA_BASE = 'por_persona_base';
    case POR_PERSONA = 'por_persona';

    public function ocultaPrecio(): bool
    {
        return $this === self::SIN_PRECIO;
    }

    /** Sin la barra «Precio total del viaje» ni el «× N pax» en la guía. */
    public function ocultaTotales(): bool
    {
        return $this !== self::TOTAL_GRUPO;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SIN_PRECIO => 'Sin precio',
            self::TOTAL_GRUPO => 'Total del grupo',
            self::POR_PERSONA_BASE => 'Por persona, con la base',
            self::POR_PERSONA => 'Solo por persona',
        };
    }
}
