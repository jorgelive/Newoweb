<?php

declare(strict_types=1);

namespace App\Cotizacion\Enum;

/**
 * Con qué marca se presenta un catálogo en la web pública.
 *
 * OpenPeru Travel es la marca de la casa (Perú). Open World Travel «by OpenPeru» es su división de
 * destinos internacionales —hoy el Caribe: los viajes de promoción a Punta Cana—. No tiene dominio
 * propio (decisión del 07/10/2026): vive dentro de openperu.pe y se distingue por logo y paleta en
 * las secciones y páginas de sus catálogos.
 *
 * Qué logo y qué colores lleva cada una lo decide la web (`App\Front\Tours\Dto\MarcaWeb`), no esto:
 * aquí sólo está la elección. Ver docs/WebPublica.md §3.
 */
enum CatalogoMarcaEnum: string
{
    case OPENPERU = 'openperu';
    case OPEN_WORLD = 'open_world';

    public function getLabel(): string
    {
        return match ($this) {
            self::OPENPERU => 'OpenPeru Travel',
            self::OPEN_WORLD => 'Open World Travel',
        };
    }
}
