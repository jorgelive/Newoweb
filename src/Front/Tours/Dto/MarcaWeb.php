<?php

declare(strict_types=1);

namespace App\Front\Tours\Dto;

use App\Cotizacion\Enum\CatalogoMarcaEnum;

/**
 * Cómo se pinta una marca en la web: nombre, logo y la clase CSS que cambia la paleta.
 *
 * El catálogo sólo guarda la elección (`CatalogoMarcaEnum`); el aspecto es cosa de la web y vive
 * aquí, junto a los logos de `public/front/marcas/` y las clases `.marca-*` de `web.css`.
 * Una marca nueva = un caso en el enum, una rama aquí, su logo y su bloque de colores.
 */
final readonly class MarcaWeb
{
    private function __construct(
        public string $clave,
        public string $nombre,
        /** Ruta pública del logo horizontal (WebP), y su tamaño real para reservar el hueco. */
        public string $logo,
        public int $logoAncho,
        public int $logoAlto,
        /** Clase que se pone en la sección o en el `<body>`: cambia los tokens de color. */
        public string $claseCss,
    ) {
    }

    public static function de(CatalogoMarcaEnum $marca): self
    {
        return match ($marca) {
            CatalogoMarcaEnum::OPENPERU => new self('openperu', 'OpenPeru Travel', 'front/marcas/openperu-travel.webp', 480, 143, 'marca-openperu'),
            CatalogoMarcaEnum::OPEN_WORLD => new self('open_world', 'Open World Travel', 'front/marcas/open-world-travel.webp', 600, 255, 'marca-open-world'),
        };
    }

    /** La de la casa: la que no hace falta anunciar en cada sección. */
    public function esLaDeLaCasa(): bool
    {
        return $this->clave === CatalogoMarcaEnum::OPENPERU->value;
    }
}
