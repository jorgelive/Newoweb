<?php

declare(strict_types=1);

namespace App\Front\Tours\Dto;

/** La ficha pública de un tour: su tarjeta, su catálogo (para «otros tours») y su galería. */
final readonly class TourFichaWeb
{
    /**
     * @param list<string> $galeria URLs relativas, en orden de itinerario
     */
    public function __construct(
        public CatalogoWeb $catalogo,
        public TourTarjetaWeb $tour,
        public array $galeria,
    ) {
    }

    /** @return list<TourTarjetaWeb> */
    public function otrosTours(): array
    {
        return array_values(array_filter(
            $this->catalogo->tours,
            fn (TourTarjetaWeb $t): bool => $t->propuesta !== $this->tour->propuesta,
        ));
    }
}
