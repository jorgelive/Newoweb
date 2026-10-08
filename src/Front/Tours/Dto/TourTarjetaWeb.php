<?php

declare(strict_types=1);

namespace App\Front\Tours\Dto;

/**
 * Un tour tal como lo pinta la web pública, ya en el idioma de la página.
 *
 * Sale de `TourTarjetaResolver::tarjetas()` (donde viven las reglas de portada y precio oculto)
 * pasado por `CatalogoWebLector`: aquí sólo hay textos resueltos, nada que decidir.
 */
final readonly class TourTarjetaWeb
{
    /**
     * @param list<PrecioDesdeWeb> $precios
     */
    public function __construct(
        public string $id,
        public int $propuesta,
        public string $titulo,
        public string $slug,
        public ?string $resumenHtml,
        public ?string $resumenPlano,
        public ?int $numDias,
        public array $precios,
        public ?string $imagenUrl,
        /** Base de pasajeros del precio por persona, si depende del grupo («base de 60»). */
        public ?int $paxBaseGrupo = null,
    ) {
    }

    /** El «desde» más bajo, para la tarjeta y para los datos estructurados. */
    public function precioPrincipal(): ?PrecioDesdeWeb
    {
        return $this->precios[0] ?? null;
    }
}
