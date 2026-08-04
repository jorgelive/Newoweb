<?php

declare(strict_types=1);

namespace App\Pms\Enum;

/**
 * Bloques en los que se agrupa el escaparate público de una unidad.
 *
 * POR QUÉ EXISTE. El catálogo reutilizaba el árbol de secciones de la GUÍA, que
 * está escrito para otra audiencia y otro momento: la guía agrupa por *cuándo lo
 * necesitas* (llegada, estancia, normas), y por eso al visitante le aparecía un
 * encabezado «Entrada a la casa · Llegada, llaves y acceso» en mitad de una
 * página comercial. El escaparate necesita agrupar por *qué vende*.
 *
 * El bloque NO vive en el ítem sino en `PmsCatalogoHasItem`: es una propiedad
 * del papel que ese ítem juega en el catálogo, no del ítem en sí. El mismo
 * contenido puede ser «Entrada a la casa» en la guía y «Ubicación» aquí, sin
 * escribirse dos veces.
 *
 * **El orden de declaración ES el orden de la página.** Se recorre con
 * `cases()`, igual que PmsTipoCargo en el desglose financiero: cambiar el orden
 * de los `case` reordena el escaparate.
 */
enum PmsCatalogoBloque: string
{
    /** Arriba del todo: el gancho. Lo que hace especial a esta unidad. */
    case Destacado = 'destacado';

    /** Qué es el sitio: el texto que describe la unidad. */
    case Descripcion = 'descripcion';

    /** Galerías por ambiente (habitaciones, cocina, baño…). */
    case Fotos = 'fotos';

    /** Equipamiento y prestaciones: wifi, cocina, calefacción, streaming. */
    case Servicios = 'servicios';

    /** Dónde está y cómo se llega, en clave comercial (no operativa). */
    case Ubicacion = 'ubicacion';

    /** Lo que conviene saber antes de reservar: horarios, política, condiciones. */
    case Normas = 'normas';

    public function getLabel(): string
    {
        return match ($this) {
            self::Destacado   => '⭐ Destacado (encabeza la página)',
            self::Descripcion => '📝 Descripción',
            self::Fotos       => '🖼️ Fotos',
            self::Servicios   => '🛎️ Servicios y equipamiento',
            self::Ubicacion   => '📍 Ubicación',
            self::Normas      => '📋 Antes de reservar',
        };
    }

    /**
     * Clave i18n del título del bloque en el escaparate. El texto vive en
     * `pax_ui_i18n`, no aquí: la página es pública y multiidioma.
     */
    public function claveI18n(): string
    {
        return 'cat_bloque_' . $this->value;
    }
}
