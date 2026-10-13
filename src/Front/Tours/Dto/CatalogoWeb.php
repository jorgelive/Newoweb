<?php

declare(strict_types=1);

namespace App\Front\Tours\Dto;

/** Un catálogo publicado en la web, con sus tours publicados, en el idioma de la página. */
final readonly class CatalogoWeb
{
    /**
     * @param list<TourTarjetaWeb> $tours
     */
    public function __construct(
        public string $slug,
        /** Para el enlace al itinerario completo en `pax` (`/catalogo/{localizador}/p/{n}`). */
        public string $localizador,
        public string $titulo,
        public ?string $descripcionHtml,
        public array $tours,
        public MarcaWeb $marca,
        /**
         * Listado en openperu.pe. `false` sólo llega por `CatalogoWebLector::paraElAgente()`: un
         * catálogo que el agente ofrece sin estar en la web (los traslados), cuyo enlace es el de
         * pax y cuyo `slug` puede venir vacío.
         */
        public bool $enLaWeb = true,
    ) {
    }

    public function tour(int $propuesta): ?TourTarjetaWeb
    {
        foreach ($this->tours as $tour) {
            if ($tour->propuesta === $propuesta) {
                return $tour;
            }
        }
        return null;
    }

    /** La portada del primer tour: la cabecera del catálogo y su imagen al compartirlo. */
    public function imagenUrl(): ?string
    {
        foreach ($this->tours as $tour) {
            if ($tour->imagenUrl !== null) {
                return $tour->imagenUrl;
            }
        }
        return null;
    }
}
