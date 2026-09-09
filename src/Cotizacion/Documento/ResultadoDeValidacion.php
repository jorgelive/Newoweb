<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\ValidacionIdentificacionEnum;

/**
 * Qué salió de mirar un documento, y qué se propone hacer con él.
 *
 * 🔑 **Separa el JUICIO de la ACCIÓN**, y por eso son dos campos y no uno. Un documento puede
 * quedar `OBSERVADO` y aun así proponerse asociar a alguien; y puede quedar `VALIDADO` sin
 * proponer nada porque ya estaba en su sitio. Mezclarlos obligaría a inventar estados como
 * «validado pero hay que asignarlo», que es donde estos enums se pudren.
 */
final readonly class ResultadoDeValidacion
{
    /**
     * @param list<Discrepancia> $discrepancias
     * @param list<string> $notas
     */
    private function __construct(
        public ValidacionIdentificacionEnum $estado,
        public array $discrepancias,
        public array $notas,
        public Accion $accion,
        public ?DatosDeDocumento $leido = null,
        /** A quién se propone asociarlo. Null cuando la acción no es asociar. */
        public ?CotizacionFilepasajero $candidato = null,
        /** Por qué se propone eso, en una frase que se lee en pantalla. */
        public string $motivo = '',
    ) {}

    public static function ilegible(string $porque): self
    {
        return new self(ValidacionIdentificacionEnum::NO_VALIDADO, [], [$porque], Accion::NINGUNA);
    }

    public static function de(
        Cotejo $cotejo,
        DatosDeDocumento $leido,
        Accion $accion,
        ?CotizacionFilepasajero $candidato = null,
        string $motivo = '',
    ): self {
        return new self($cotejo->estado, $cotejo->discrepancias, $cotejo->notas, $accion, $leido, $candidato, $motivo);
    }
}
